<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function collection(?User $owner = null, array $data = []): UploadSession
    {
        return UploadSession::create(array_merge(['user_id' => ($owner ?? User::factory()->create())->id, 'title' => 'Weekend memories', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()], $data));
    }

    private function upload(UploadSession $c, array $files = [])
    {
        return $this->post('/u/'.$c->token, ['photos' => $files ?: [UploadedFile::fake()->image('memory.jpg', 40, 30)]]);
    }

    public function test_admin_login_and_logout(): void
    {
        $u = User::factory()->create(['password' => bcrypt('correct-password')]);
        $this->get('/')->assertRedirect('/login');
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $u->email, 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'x@example.test', 'password' => 'bad'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'x@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_owner_creates_collection_with_working_qr(): void
    {
        $this->actingAs(User::factory()->create())->post('/collections', ['title' => 'Our trip', 'hours' => 24])->assertRedirect();
        $c = UploadSession::firstOrFail();
        $this->assertEquals(64, strlen($c->token));
        $this->get('/collections/'.$c->id)->assertOk()->assertSee('<svg', false)->assertSee('/u/'.$c->token)->assertSee('Our trip');
        $this->assertDatabaseHas('audit_events', ['action' => 'collection.created', 'resource_id' => $c->id]);
    }

    public function test_collection_validation(): void
    {
        $this->actingAs(User::factory()->create())->post('/collections', ['title' => '', 'hours' => 169])->assertSessionHasErrors(['title', 'hours']);
    }

    public function test_guest_uploads_private_images_with_safe_names(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->upload($c, [UploadedFile::fake()->image('../../evil.jpg', 40, 30), UploadedFile::fake()->image('second.png', 30, 20)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(2, $c->photos()->count());
        $photo = $c->photos()->first();
        Storage::disk('local')->assertExists($photo->path);
        $this->assertStringNotContainsString('evil', $photo->path);
        $this->assertEquals('image/jpeg', $photo->mime);
        $this->get('/photos/'.$photo->id)->assertRedirect('/login');
        $this->get('/storage/'.$photo->path)->assertNotFound();
    }

    public function test_guest_page_does_not_list_photos(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->upload($c);
        $this->get('/u/'.$c->token)->assertOk()->assertSee('Add your photos')->assertDontSee('/photos/');
    }

    public function test_expired_closed_unknown_links_reject_uploads(): void
    {
        Storage::fake('local');
        foreach ([['expires_at' => now()->subSecond()], ['closed_at' => now()]] as $data) {
            $c = $this->collection(null, $data);
            $this->get('/u/'.$c->token)->assertStatus(410);
            $this->upload($c)->assertStatus(410);
        }
        $this->get('/u/'.str_repeat('0', 64))->assertNotFound();
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_invalid_mime_and_oversized_files(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->upload($c, [UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')])->assertSessionHasErrors('photos.0');
        $this->upload($c, [UploadedFile::fake()->image('big.png')->size(10241)])->assertSessionHasErrors('photos.0');
        $this->upload($c, [UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertSessionHasErrors('photos.0');
        $this->assertDatabaseCount('photos', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_empty_and_too_many_files_rejected(): void
    {
        $c = $this->collection();
        $this->post('/u/'.$c->token, ['photos' => []])->assertSessionHasErrors('photos');
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image('p'.$i.'.jpg');
        }
        $this->upload($c, $files)->assertSessionHasErrors('photos');
    }

    public function test_session_capacity_is_enforced(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        for ($i = 0; $i < 99; $i++) {
            $c->photos()->create(['path' => 'photos/'.$i.'.jpg', 'mime' => 'image/jpeg', 'bytes' => 1, 'width' => 1, 'height' => 1]);
        }
        $this->upload($c, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])->assertSessionHasErrors('photos');
        $this->assertEquals(99, $c->photos()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->upload($c)->assertSessionHasNoErrors();
        $this->assertEquals(100, $c->photos()->count());
    }

    public function test_foreign_owner_cannot_view_close_download_delete(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->upload($c);
        $photo = $c->photos()->firstOrFail();
        $this->actingAs(User::factory()->create());
        $this->get('/collections/'.$c->id)->assertNotFound();
        $this->post('/collections/'.$c->id.'/close')->assertNotFound();
        $this->get('/photos/'.$photo->id)->assertNotFound();
        $this->get('/photos/'.$photo->id.'?download=1')->assertNotFound();
        $this->delete('/photos/'.$photo->id)->assertNotFound();
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_owner_download_delete_close_and_dashboard(): void
    {
        Storage::fake('local');
        $u = User::factory()->create();
        $c = $this->collection($u);
        $this->upload($c);
        $photo = $c->photos()->firstOrFail();
        $this->actingAs($u)->get('/')->assertOk()->assertSee('Weekend memories');
        $this->get('/photos/'.$photo->id)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/photos/'.$photo->id.'?download=1')->assertOk()->assertDownload('photo-'.$photo->id.'.jpg');
        $this->delete('/photos/'.$photo->id)->assertRedirect();
        Storage::disk('local')->assertMissing($photo->path);
        $this->assertDatabaseCount('photos', 0);
        $this->post('/collections/'.$c->id.'/close')->assertRedirect();
        $this->assertNotNull($c->fresh()->closed_at);
        $this->upload($c)->assertStatus(410);
    }

    public function test_storage_failure_rolls_back_metadata_and_cleans_partial_batch(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $storage = Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturn($storage, new class
        {
            public function putFileAs(...$args)
            {
                throw new \RuntimeException('Disk unavailable');
            }
        }, $storage);
        $this->withoutExceptionHandling();
        try {
            $this->upload($c, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Disk unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('photos', 0);
        $this->assertEmpty($storage->allFiles());
    }

    public function test_mixed_valid_invalid_batch_is_atomic(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->upload($c, [UploadedFile::fake()->image('valid.jpg'), UploadedFile::fake()->createWithContent('fake.png', 'broken')])->assertSessionHasErrors('photos.1');
        $this->assertDatabaseCount('photos', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_request_headers_and_html_escaping(): void
    {
        $u = User::factory()->create();
        $c = $this->collection($u, ['title' => '<script>alert(1)</script>']);
        $this->get('/u/'.$c->token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_guest_activity_does_not_consume_login_limit(): void
    {
        $c = $this->collection();
        for ($i = 0; $i < 6; $i++) {
            $this->get('/u/'.$c->token)->assertOk();
        }
        $user = User::factory()->create(['password' => bcrypt('login-after-upload')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'login-after-upload'])->assertRedirect('/');
    }
}
