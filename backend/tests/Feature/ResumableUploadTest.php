<?php

namespace Tests\Feature;

use App\Models\ResumableUpload;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResumableUploadTest extends TestCase
{
    use RefreshDatabase;

    private function collection(array $attributes = []): UploadSession
    {
        return UploadSession::create($attributes + ['user_id' => User::factory()->create()->id, 'title' => 'Resume event', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDays(3)]);
    }

    private function start(UploadSession $c, ?string $bytes = null, ?string $id = null): array
    {
        $source = UploadedFile::fake()->image('resume.png', 400, 300);
        $bytes ??= file_get_contents($source->getRealPath());
        $data = ['id' => $id ?? (string) Str::uuid(), 'name' => 'resume.png', 'mime' => 'image/png', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $this->withHeader('X-Upload-Secret', str_repeat('a', 64))->postJson('/u/'.$c->token.'/resumable', $data)->assertCreated();

        return [$data, $bytes, '/u/'.$c->token.'/resumable/'.$data['id']];
    }

    private function chunk(string $url, int $offset, string $bytes)
    {
        return $this->post($url.'/chunks', ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes)], ['Accept' => 'application/json']);
    }

    public function test_saved_chunks_resume_retry_safely_and_finalize_once_even_after_close(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        [$data,$bytes,$url] = $this->start($c);
        $first = substr($bytes, 0, 100);
        $this->chunk($url, 0, $first)->assertOk()->assertJsonPath('data.offset', 100);
        $this->chunk($url, 0, $first)->assertOk()->assertJsonPath('data.offset', 100);
        $this->chunk($url, 0, str_repeat('x', 100))->assertConflict();
        $this->postJson('/u/'.$c->token.'/resumable', $data)->assertOk()->assertJsonPath('data.offset', 100);
        $this->postJson($url.'/finish')->assertConflict();
        $this->chunk($url, 100, substr($bytes, 100))->assertOk()->assertJsonPath('data.offset', strlen($bytes));
        $this->postJson($url.'/finish')->assertOk()->assertJsonPath('data.uploaded', 1);
        $c->update(['closed_at' => now()]);
        $this->postJson($url.'/finish')->assertOk()->assertJsonPath('data.uploaded', 1);
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
        $this->assertDatabaseCount('jobs', 1);
        $photo = $c->photos()->firstOrFail();
        $this->assertSame($bytes, Storage::disk('local')->get($photo->path));
        Storage::disk('local')->assertMissing(ResumableUpload::firstOrFail()->partFile());
    }

    public function test_secret_collection_password_and_expiry_protect_staging_and_completion(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        [$data,$bytes,$url] = $this->start($c);
        $this->withHeader('X-Upload-Secret', str_repeat('b', 64))->chunk($url, 0, 'data')->assertNotFound();
        $other = $this->collection();
        $this->withHeader('X-Upload-Secret', str_repeat('a', 64))->postJson('/u/'.$other->token.'/resumable', $data)->assertNotFound();
        $c->update(['password_hash' => Hash::make('new-password')]);
        $this->chunk($url, 0, 'data')->assertForbidden();
        $this->post('/u/'.$c->token.'/unlock', ['password' => 'new-password'])->assertRedirect();
        $this->chunk($url, 0, $bytes)->assertOk();
        $c->update(['password_hash' => Hash::make('changed-password')]);
        $this->postJson($url.'/finish')->assertForbidden();
        $this->post('/u/'.$c->token.'/unlock', ['password' => 'changed-password'])->assertRedirect();
        ResumableUpload::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->postJson($url.'/finish')->assertGone();
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_partial_file_crash_reconciliation_discards_uncommitted_tail_and_recovers_missing_staging(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        [$data,$bytes,$url] = $this->start($c);
        $this->chunk($url, 0, substr($bytes, 0, 100))->assertOk();
        $row = ResumableUpload::firstOrFail();
        Storage::disk('local')->append($row->partFile(), 'uncommitted');
        $this->postJson('/u/'.$c->token.'/resumable', $data)->assertOk()->assertJsonPath('data.offset', 100);
        $this->assertSame(substr($bytes, 0, 100), Storage::disk('local')->get($row->partFile()));
        Storage::disk('local')->delete($row->partFile());
        $this->postJson('/u/'.$c->token.'/resumable', $data)->assertOk()->assertJsonPath('data.offset', 0);
        $this->chunk($url, 0, $bytes)->assertOk();
        $this->postJson($url.'/finish')->assertOk();
    }

    public function test_bad_checksum_invalid_image_chunk_bounds_and_metadata_conflicts_are_rejected(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        [$data,$bytes,$url] = $this->start($c);
        $this->postJson('/u/'.$c->token.'/resumable', array_merge($data, ['bytes' => $data['bytes'] + 1]))->assertConflict();
        $this->chunk($url, 1, 'data')->assertConflict();
        $this->chunk($url, 0, str_repeat('x', 524289))->assertUnprocessable();
        $this->chunk($url, 0, str_repeat('x', strlen($bytes)))->assertOk();
        $this->postJson($url.'/finish')->assertUnprocessable();
        [$invalid,$raw,$otherUrl] = $this->start($c, 'not-an-image');
        $this->chunk($otherUrl, 0, $raw)->assertOk();
        $this->postJson($otherUrl.'/finish')->assertUnprocessable();
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_final_quota_and_closed_collection_are_enforced_without_creating_photo(): void
    {
        Storage::fake('local');
        $c = $this->collection(['quota_bytes' => 1]);
        [$data,$bytes,$url] = $this->start($c);
        $this->chunk($url, 0, $bytes)->assertOk();
        $this->postJson($url.'/finish')->assertUnprocessable();
        $this->assertDatabaseCount('photos', 0);
        $this->assertDatabaseCount('upload_receipts', 0);
        $c->update(['closed_at' => now()]);
        $this->postJson($url.'/finish')->assertGone();
    }

    public function test_abandon_and_expiry_cleanup_remove_private_staging(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        [$data,$bytes,$url] = $this->start($c);
        $this->chunk($url, 0, $bytes)->assertOk();
        $path = ResumableUpload::firstOrFail()->partFile();
        $this->deleteJson($url)->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('resumable_uploads', 0);
        [$data,$bytes,$url] = $this->start($c);
        $this->chunk($url, 0, $bytes)->assertOk();
        $row = ResumableUpload::firstOrFail();
        $row->update(['expires_at' => now()->subSecond()]);
        $this->artisan('uploads:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($row->partFile());
        $this->assertDatabaseCount('resumable_uploads', 0);
    }

    public function test_unfinished_upload_count_is_bounded_and_bad_metadata_is_rejected(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        for ($i = 0; $i < 20; $i++) {
            $this->start($c);
        }
        $this->postJson('/u/'.$c->token.'/resumable', ['id' => (string) Str::uuid(), 'name' => 'photo.png', 'mime' => 'image/png', 'bytes' => 10, 'sha256' => str_repeat('a', 64)])->assertTooManyRequests();
        $this->postJson('/u/'.$c->token.'/resumable', ['id' => 'invalid', 'name' => 'a', 'mime' => 'image/svg+xml', 'bytes' => 10485761, 'sha256' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['id', 'mime', 'bytes', 'sha256']);
    }
}
