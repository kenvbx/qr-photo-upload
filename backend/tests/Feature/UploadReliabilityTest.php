<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function collection(array $data = []): UploadSession
    {
        return UploadSession::create(array_merge(['user_id' => User::factory()->create()->id, 'title' => 'Reliable uploads', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()], $data));
    }

    private function bytes(int $width = 40): string
    {
        $file = UploadedFile::fake()->image('source.png', $width, 30);

        return file_get_contents($file->getRealPath());
    }

    private function send(UploadSession $collection, string $bytes, ?string $key = null)
    {
        return $this->withHeader('Accept', 'application/json')->post('/u/'.$collection->token, [
            'photos' => [UploadedFile::fake()->createWithContent('renamed.png', $bytes)],
            'upload_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    public function test_same_request_can_be_replayed_without_extra_file_audit_or_quota(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $key = (string) Str::uuid();
        $this->send($c, $bytes, $key)->assertOk()->assertExactJson(['data' => ['uploaded' => 1, 'duplicates' => 0, 'replayed' => false]]);
        $this->send($c, $bytes, strtoupper($key))->assertOk()->assertExactJson(['data' => ['uploaded' => 1, 'duplicates' => 0, 'replayed' => true]]);
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_same_request_id_with_different_bytes_is_a_conflict(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $key = (string) Str::uuid();
        $this->send($c, $this->bytes(), $key)->assertOk();
        $this->send($c, $this->bytes(41), $key)->assertConflict()->assertJsonPath('message', 'This upload ID was already used for different photos. Choose the files again.');
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_new_request_id_and_filename_do_not_duplicate_identical_content(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $this->send($c, $bytes)->assertOk();
        $this->send($c, $bytes)->assertOk()->assertExactJson(['data' => ['uploaded' => 0, 'duplicates' => 1, 'replayed' => false]]);
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 2);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_same_content_and_request_id_are_scoped_to_collection(): void
    {
        Storage::fake('local');
        $a = $this->collection();
        $b = $this->collection();
        $bytes = $this->bytes();
        $key = (string) Str::uuid();
        $this->send($a, $bytes, $key)->assertOk();
        $this->send($b, $bytes, $key)->assertOk()->assertJsonPath('data.uploaded', 1)->assertJsonPath('data.replayed', false);
        $this->assertDatabaseCount('photos', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_json_requests_need_a_valid_upload_id(): void
    {
        $c = $this->collection();
        foreach ([null, 'invalid'] as $key) {
            $this->withHeader('Accept', 'application/json')->post('/u/'.$c->token, ['photos' => [UploadedFile::fake()->image('photo.png')], 'upload_key' => $key])->assertUnprocessable()->assertJsonValidationErrors('upload_key');
        }
        $this->assertDatabaseCount('photos', 0);
        $this->assertDatabaseCount('upload_receipts', 0);
    }

    public function test_closed_and_expired_collections_acknowledge_only_completed_requests(): void
    {
        Storage::fake('local');
        foreach ([['closed_at' => now()], ['expires_at' => now()->subSecond()]] as $closed) {
            $c = $this->collection();
            $bytes = $this->bytes();
            $key = (string) Str::uuid();
            $this->send($c, $bytes, $key)->assertOk();
            $c->update($closed);
            $this->send($c, $bytes, $key)->assertOk()->assertJsonPath('data.replayed', true);
            $this->send($c, $bytes)->assertGone();
        }
        $this->assertDatabaseCount('photos', 2);
        $this->assertDatabaseCount('upload_receipts', 2);
    }

    public function test_duplicate_content_does_not_consume_remaining_capacity(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $this->send($c, $bytes)->assertOk();
        for ($index = 0; $index < 99; $index++) {
            $c->photos()->create(['path' => 'missing/'.$index.'.png', 'mime' => 'image/png', 'bytes' => 1, 'width' => 1, 'height' => 1, 'sha256' => hash('sha256', 'fixture-'.$index)]);
        }
        $this->send($c, $bytes)->assertOk()->assertJsonPath('data.duplicates', 1);
        $this->send($c, $this->bytes(42))->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertDatabaseCount('photos', 100);
        $this->assertDatabaseCount('upload_receipts', 2);
    }

    public function test_identical_photos_in_one_native_batch_are_saved_once(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $key = (string) Str::uuid();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post('/u/'.$c->token, ['upload_key' => $key, 'photos' => [
                UploadedFile::fake()->createWithContent('first.png', $bytes), UploadedFile::fake()->createWithContent('second.png', $bytes),
            ]])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_storage_failure_does_not_leave_receipt_and_same_key_can_retry(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $key = (string) Str::uuid();
        $disk = Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturn(new class
        {
            public function putFileAs(...$arguments)
            {
                return false;
            }
        }, $disk);
        $this->withoutExceptionHandling();
        try {
            $this->send($c, $bytes, $key);
            $this->fail('Expected storage error');
        } catch (\RuntimeException $error) {
            $this->assertSame('Photo storage failed.', $error->getMessage());
        }
        $this->assertDatabaseCount('photos', 0);
        $this->assertDatabaseCount('upload_receipts', 0);
        $this->assertEmpty($disk->allFiles());
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->send($c, $bytes, $key)->assertOk()->assertJsonPath('data.uploaded', 1);
    }

    public function test_deleted_photo_is_not_resurrected_by_old_request(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        $key = (string) Str::uuid();
        $this->send($c, $bytes, $key)->assertOk();
        $photo = $c->photos()->firstOrFail();
        $this->actingAs(User::findOrFail($c->user_id))->delete('/photos/'.$photo->id)->assertRedirect();
        $this->delete(route('trash.purge', $photo->id))->assertRedirect();
        $this->send($c, $bytes, $key)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('photos', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_legacy_photo_hash_is_filled_without_removing_existing_files(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $bytes = $this->bytes();
        Storage::disk('local')->put('old.png', $bytes);
        $photo = $c->photos()->create(['path' => 'old.png', 'mime' => 'image/png', 'bytes' => strlen($bytes), 'width' => 40, 'height' => 30]);
        $this->send($c, $bytes)->assertOk()->assertJsonPath('data.duplicates', 1);
        $this->assertSame(hash('sha256', $bytes), $photo->fresh()->sha256);
        $this->assertDatabaseCount('photos', 1);
        Storage::disk('local')->assertExists('old.png');
    }
}
