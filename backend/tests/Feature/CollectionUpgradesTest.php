<?php

namespace Tests\Feature;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PhotoExportBuilder;
use App\Services\PhotoThumbnail;
use App\Services\PhotoUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CollectionUpgradesTest extends TestCase
{
    use RefreshDatabase;

    private function collection(array $data = []): UploadSession
    {
        return UploadSession::create(array_merge(['user_id' => User::factory()->create()->id, 'title' => 'Private event', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()], $data));
    }

    public function test_password_guards_get_upload_and_receipt_replay_and_change_revokes_grant(): void
    {
        Storage::fake('local');
        $c = $this->collection(['password_hash' => Hash::make('first-secret')]);
        $file = UploadedFile::fake()->image('test.jpg');
        $key = 'e6a3723d-f67e-4637-84ce-1b74e63ff841';
        $payload = ['photos' => [$file], 'upload_key' => $key];
        $this->get('/u/'.$c->token)->assertOk()->assertSee('Private upload link')->assertDontSee($c->title);
        $this->postJson('/u/'.$c->token, $payload)->assertForbidden();
        $this->post('/u/'.$c->token.'/unlock', ['password' => 'bad'])->assertSessionHasErrors('password');
        $this->post('/u/'.$c->token.'/unlock', ['password' => 'first-secret'])->assertRedirect('/u/'.$c->token);
        $this->get('/u/'.$c->token)->assertSee($c->title);
        $this->postJson('/u/'.$c->token, $payload)->assertOk()->assertJsonPath('data.uploaded', 1);
        $c->update(['password_hash' => Hash::make('next-secret')]);
        $this->postJson('/u/'.$c->token, $payload)->assertForbidden();
        $this->assertSame(1, $c->photos()->count());
        $this->get('/u/'.$c->token)->assertDontSee($c->title);
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        $c = $this->collection(['password_hash' => Hash::make('first-secret')]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/u/'.$c->token.'/unlock', ['password' => 'bad'])->assertSessionHasErrors();
        }
        $this->post('/u/'.$c->token.'/unlock', ['password' => 'first-secret'])->assertTooManyRequests();
    }

    public function test_byte_quota_counts_only_new_files_and_compensates_rejection(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('test.jpg', 50, 30);
        $c = $this->collection(['quota_bytes' => $file->getSize()]);
        $this->postJson('/u/'.$c->token, ['photos' => [$file], 'upload_key' => fake()->uuid()])->assertOk();
        $this->postJson('/u/'.$c->token, ['photos' => [$file], 'upload_key' => fake()->uuid()])->assertOk()->assertJsonPath('data.duplicates', 1);
        $this->postJson('/u/'.$c->token, ['photos' => [UploadedFile::fake()->image('new.jpg', 51, 30)], 'upload_key' => fake()->uuid()])->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertSame(1, $c->photos()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('photos'));
    }

    public function test_settings_and_task_endpoints_are_owner_only_and_retention_is_explicit(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $owner = User::findOrFail($c->user_id);
        $this->get('/tasks')->assertRedirect('/login');
        $this->post('/u/'.$c->token, ['photos' => [UploadedFile::fake()->image('old.jpg', 20, 20)]])->assertRedirect();
        $photo = $c->photos()->firstOrFail();
        $this->actingAs(User::factory()->create())->post('/collections/'.$c->id.'/settings', ['quota_mb' => 50])->assertNotFound();
        $this->getJson('/collections/'.$c->id)->assertNotFound();
        $this->post('/photos/'.$photo->id.'/retry')->assertNotFound();
        $this->actingAs($owner)->post('/collections/'.$c->id.'/settings', ['quota_mb' => 50, 'retention_days' => 2, 'password' => 'new-secret'])->assertRedirect();
        $this->assertTrue(Hash::check('new-secret', $c->fresh()->password_hash));
        $this->assertNull($photo->fresh()->delete_after);
        $this->get('/tasks')->assertOk()->assertSee('Photo '.$photo->id);
        $this->actingAs(User::factory()->create())->getJson('/tasks')->assertOk()->assertDontSee('Photo '.$photo->id);
        $this->actingAs($owner)->post('/collections/'.$c->id.'/settings', ['quota_mb' => 50, 'remove_password' => 1])->assertRedirect();
        $this->assertNull($c->fresh()->password_hash);
        $this->post('/collections/'.$c->id.'/settings', ['quota_mb' => 0, 'retention_days' => 366, 'password' => 'short'])->assertSessionHasErrors(['quota_mb', 'retention_days', 'password']);
    }

    public function test_retention_prunes_original_and_thumbnail_but_keeps_unexpired_photos(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake('local');
        $c = $this->collection(['retention_days' => 2, 'expires_at' => now()->addWeek()]);
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('expired.jpg')]);
        $expired = $c->photos()->firstOrFail();
        app(PhotoThumbnail::class)->generate($expired->id);
        $this->assertTrue($expired->fresh()->delete_after->equalTo(now()->addDays(2)));
        $this->travel(1)->days();
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('retained.jpg', 30, 40)]);
        $this->travel(1)->days();
        $this->artisan('photos:prune')->assertSuccessful();
        $this->assertDatabaseMissing('photos', ['id' => $expired->id]);
        Storage::disk('local')->assertMissing([$expired->path, $expired->thumbnailFile()]);
        $this->assertSame(1, $c->photos()->count());
    }

    public function test_gallery_search_literal_wildcards_filters_sort_and_live_revision(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('100%_party.png', 20, 30), UploadedFile::fake()->image('<script>.jpg', 21, 31)]);
        $this->actingAs(User::findOrFail($c->user_id));
        $url = '/collections/'.$c->id;
        $first = $this->getJson($url)->assertOk();
        $this->assertSame($first->json('revision'), $this->getJson($url)->json('revision'));
        $this->getJson($url.'?q=%25_&type=png&sort=oldest')->assertOk()->assertSee('100%_party.png')->assertDontSee('&lt;script&gt;.jpg');
        $this->get($url)->assertSee('&lt;script&gt;.jpg', false)->assertDontSee('<script>.jpg', false);
        $this->getJson($url.'?sort=bytes;drop')->assertUnprocessable();
        $photo = $c->photos()->firstOrFail();
        app(PhotoThumbnail::class)->generate($photo->id);
        $this->assertNotSame($first->json('revision'), $this->getJson($url)->json('revision'));
    }

    public function test_failed_thumbnail_retry_is_idempotent_and_recovers(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('photo.jpg')]);
        $photo = $c->photos()->firstOrFail();
        $photo->update(['thumbnail_status' => 'failed']);
        Queue::fake([GeneratePhotoThumbnail::class]);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post('/photos/'.$photo->id.'/retry')->assertRedirect();
        $this->post('/photos/'.$photo->id.'/retry')->assertRedirect();
        Queue::assertPushed(GeneratePhotoThumbnail::class, 1);
        $this->assertSame('pending', $photo->fresh()->thumbnail_status);
        app(PhotoThumbnail::class)->generate($photo->id);
        $this->assertSame('ready', $photo->fresh()->thumbnail_status);
    }

    public function test_remote_zip_read_failure_removes_staging_and_never_publishes_partial_archive(): void
    {
        Storage::fake('local');
        $remote = Storage::fake('s3');
        config(['photos.disk' => 's3']);
        $c = $this->collection();
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('source.png')]);
        $photo = $c->photos()->firstOrFail();
        $export = app(PhotoExportBuilder::class)->request($c, [$photo->id]);
        $manager = Storage::getFacadeRoot();
        $broken = \Mockery::mock($remote)->makePartial();
        $broken->shouldReceive('readStream')->with($photo->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($broken);
        Storage::shouldReceive('disk')->with('local')->andReturn($manager->disk('local'));
        try {
            app(PhotoExportBuilder::class)->build($export->id);
            $this->fail('An incomplete source must fail the ZIP.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Unable to read source.', $error->getMessage());
        } finally {
            Storage::swap($manager);
        }
        Storage::disk('local')->assertMissing([$export->archiveFile(), $export->temporaryFile()]);
        $this->assertSame([], Storage::disk('local')->allDirectories('exports'));
        $this->assertNotSame('ready', $export->fresh()->status);
    }

    public function test_quota_cannot_drop_below_used_bytes_and_retention_failure_keeps_photo_metadata(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $c->photos()->create(['path' => 'photos/missing.jpg', 'mime' => 'image/jpeg', 'bytes' => 2097152, 'width' => 10, 'height' => 10, 'delete_after' => now()->subDay()]);
        $this->actingAs(User::findOrFail($c->user_id))->post('/collections/'.$c->id.'/settings', ['quota_mb' => 1])->assertSessionHasErrors('quota_mb');
        $this->assertSame(1048576000, (int) $c->fresh()->quota_bytes);
        $disk = Storage::disk('local');
        $manager = Storage::getFacadeRoot();
        $broken = \Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('delete')->with($photo->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($broken);
        try {
            $this->artisan('photos:prune')->assertFailed();
        } finally {
            Storage::swap($manager);
        }
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }

    #[TestWith(['r2'])]
    #[TestWith(['s3'])]
    public function test_cloud_photo_lifecycle_uses_streams_and_preserves_local_photos(string $disk): void
    {
        Storage::fake('local');
        $cloud = Storage::fake($disk);
        $manager = Storage::getFacadeRoot();
        $remote = \Mockery::mock($cloud)->makePartial();
        $remote->shouldReceive('path')->never();
        Storage::shouldReceive('disk')->with($disk)->andReturn($remote);
        Storage::shouldReceive('disk')->with('local')->andReturn($manager->disk('local'));
        $c = $this->collection();
        config(['photos.disk' => 'local']);
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('local.jpg', 30, 20)]);
        config(['photos.disk' => $disk]);
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('cloud.jpg', 40, 30)]);
        $photo = $c->photos()->where('disk', $disk)->firstOrFail();
        app(PhotoThumbnail::class)->generate($photo->id);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/photos/'.$photo->id)->assertOk();
        $this->get('/photos/'.$photo->id.'/thumbnail')->assertOk();
        $export = app(PhotoExportBuilder::class)->request($c, $c->photos()->pluck('id')->all());
        app(PhotoExportBuilder::class)->build($export->id);
        $archive = new \PharData($manager->disk('local')->path($export->archiveFile()));
        $this->assertCount(2, $archive);
        $this->assertSame($cloud->get($photo->path), $archive['photo-'.$photo->id.'.jpg']->getContent());
        $this->assertSame([], $manager->disk('local')->allDirectories('exports'));
        $this->delete('/photos/'.$photo->id)->assertRedirect();
        $cloud->assertMissing([$photo->path, $photo->thumbnailFile()]);
        $this->assertSame(1, $c->photos()->count());
        Storage::swap($manager);
    }
}
