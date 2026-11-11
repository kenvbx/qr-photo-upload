<?php

namespace Tests\Feature;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\Photo;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PhotoThumbnail;
use App\Services\PhotoUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PhotoThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private function collection(): UploadSession
    {
        return UploadSession::create(['user_id' => User::factory()->create()->id, 'title' => 'Thumbnails', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()]);
    }

    private function photo(UploadSession $collection, string $extension = 'png', int $width = 1200, int $height = 800): Photo
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 74, 124, 94));
        ob_start();
        match ($extension) {
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
            default => imagepng($image),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = 'photos/'.$collection->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return $collection->photos()->create(['path' => $path, 'mime' => ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'png' => 'image/png'][$extension], 'bytes' => strlen($bytes), 'width' => $width, 'height' => $height]);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'thumbnails', '--once' => true, '--sleep' => 0, '--memory' => 256])->assertSuccessful();
    }

    public function test_upload_commits_one_durable_job_and_replay_does_not_enqueue_again(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $file = UploadedFile::fake()->image('photo.png', 1200, 800);
        $key = (string) Str::uuid();
        app(PhotoUpload::class)->store($collection, [$file], $key);
        app(PhotoUpload::class)->store($collection, [$file], $key);
        app(PhotoUpload::class)->store($collection, [$file], (string) Str::uuid());
        $photo = $collection->photos()->firstOrFail();
        $this->assertSame('pending', $photo->thumbnail_status);
        Storage::disk('local')->assertMissing($photo->thumbnailFile());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'thumbnails', 'attempts' => 0]);
        $this->work();
        $this->assertSame('ready', $photo->fresh()->thumbnail_status);
        Storage::disk('local')->assertExists($photo->thumbnailFile());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_insert_failure_rolls_back_receipt_photo_and_private_original(): void
    {
        Storage::fake('local');
        $queue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->shouldReceive('push')->once()->andThrow(new RuntimeException('Queue storage unavailable'));
        Queue::shouldReceive('connection')->with('database')->andReturn($queue);
        try {
            app(PhotoUpload::class)->store($this->collection(), [UploadedFile::fake()->image('photo.png')], (string) Str::uuid());
            $this->fail('Expected queue storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue storage unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('photos', 0);
        $this->assertDatabaseCount('upload_receipts', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_supported_sources_create_bounded_jpeg_thumbnails_without_changing_originals_and_replays_are_safe(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $photo = $this->photo($collection, $extension);
            $original = Storage::disk('local')->get($photo->path);
            app(PhotoThumbnail::class)->generate($photo->id);
            $bytes = Storage::disk('local')->get($photo->thumbnailFile());
            $dimensions = getimagesizefromstring($bytes);
            $this->assertSame([480, 320], [$dimensions[0], $dimensions[1]]);
            $this->assertSame('image/jpeg', $dimensions['mime']);
            $this->assertDatabaseHas('photos', ['id' => $photo->id, 'thumbnail_status' => 'ready', 'thumbnail_width' => 480, 'thumbnail_height' => 320, 'thumbnail_bytes' => strlen($bytes)]);
            app(PhotoThumbnail::class)->generate($photo->id);
            $this->assertSame($bytes, Storage::disk('local')->get($photo->thumbnailFile()));
            $this->assertSame($original, Storage::disk('local')->get($photo->path));
        }
        $small = $this->photo($collection, 'png', 40, 30);
        app(PhotoThumbnail::class)->generate($small->id);
        $this->assertSame(40, $small->fresh()->thumbnail_width);
        $this->assertSame(30, $small->fresh()->thumbnail_height);
        $this->assertCount(8, Storage::disk('local')->allFiles());
    }

    public function test_pending_and_failed_previews_use_private_placeholders_and_ready_thumbnails_enforce_ownership(): void
    {
        Storage::fake('local');
        $photo = $this->photo($this->collection());
        $owner = User::findOrFail($photo->uploadSession->user_id);
        $url = route('photos.thumbnail', $photo);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('Preview processing');
        $photo->update(['thumbnail_status' => 'failed']);
        $this->get($url)->assertOk()->assertSee('Preview unavailable');
        $this->get(route('photos.show', $photo))->assertOk();
        app(PhotoThumbnail::class)->generate($photo->id);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($photo->thumbnailFile()), $response->streamedContent());
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        Storage::disk('local')->delete($photo->thumbnailFile());
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Preview unavailable');
    }

    public function test_delete_before_or_after_processing_removes_derivatives_and_late_jobs_cannot_resurrect_photos(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $this->actingAs(User::findOrFail($collection->user_id));
        foreach ([false, true] as $processed) {
            $photo = $this->photo($collection);
            if ($processed) {
                app(PhotoThumbnail::class)->generate($photo->id);
            }
            $this->post(route('photos.selection', $collection), ['action' => 'delete', 'photos' => [$photo->id]])->assertRedirect();
            (new GeneratePhotoThumbnail($photo->id))->handle(app(PhotoThumbnail::class));
            Storage::disk('local')->assertMissing([$photo->path, $photo->thumbnailFile()]);
            $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        }
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_partial_thumbnail_write_is_cleaned_up_and_can_be_retried_without_touching_original(): void
    {
        Storage::fake('local');
        $photo = $this->photo($this->collection());
        $disk = Storage::disk('local');
        $original = $disk->get($photo->path);
        $manager = Storage::getFacadeRoot();
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->with($photo->thumbnailFile().'.tmp', \Mockery::any())->once()->andReturnUsing(function ($path) use ($disk) {
            $disk->put($path, 'partial');

            return false;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        try {
            app(PhotoThumbnail::class)->generate($photo->id);
            $this->fail('Expected thumbnail storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Thumbnail storage failed.', $exception->getMessage());
        }
        $disk->assertMissing($photo->thumbnailFile());
        $disk->assertMissing($photo->thumbnailFile().'.tmp');
        $this->assertSame('pending', $photo->fresh()->thumbnail_status);
        $this->assertSame($original, $disk->get($photo->path));
        Storage::swap($manager);
        app(PhotoThumbnail::class)->generate($photo->id);
        $this->assertSame('ready', $photo->fresh()->thumbnail_status);
    }

    public function test_worker_retries_then_records_exhaustion_and_backfill_can_recover_failed_photo(): void
    {
        Storage::fake('local');
        $photo = $this->photo($this->collection());
        $bytes = Storage::disk('local')->get($photo->path);
        Storage::disk('local')->delete($photo->path);
        GeneratePhotoThumbnail::dispatch($photo->id);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->work();
            if ($attempt < 3) {
                $this->assertDatabaseHas('jobs', ['queue' => 'thumbnails', 'attempts' => $attempt]);
                $this->assertSame('pending', $photo->fresh()->thumbnail_status);
                $this->assertGreaterThan(now()->timestamp, DB::table('jobs')->value('available_at'));
                DB::table('jobs')->update(['available_at' => 0]);
            }
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame('failed', $photo->fresh()->thumbnail_status);
        Storage::disk('local')->put($photo->path, $bytes);
        $this->artisan('photos:thumbnails')->expectsOutput('0 thumbnail jobs queued.')->assertSuccessful();
        $this->artisan('photos:thumbnails', ['--retry-failed' => true])->expectsOutput('1 thumbnail jobs queued.')->assertSuccessful();
        $this->work();
        $this->assertSame('ready', $photo->fresh()->thumbnail_status);
    }

    public function test_backfill_scopes_to_collection_skips_ready_and_repairs_missing_derivatives(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $pending = $this->photo($collection);
        $ready = $this->photo($collection);
        $other = $this->photo($this->collection());
        app(PhotoThumbnail::class)->generate($ready->id);
        $this->artisan('photos:thumbnails', ['--collection' => (string) $collection->id])->expectsOutput('1 thumbnail jobs queued.')->assertSuccessful();
        $this->work();
        $this->assertSame('ready', $pending->fresh()->thumbnail_status);
        $this->assertSame('pending', $other->fresh()->thumbnail_status);
        Storage::disk('local')->delete($ready->thumbnailFile());
        $this->artisan('photos:thumbnails', ['--collection' => (string) $collection->id])->expectsOutput('1 thumbnail jobs queued.')->assertSuccessful();
        $this->work();
        Storage::disk('local')->assertExists($ready->thumbnailFile());
        $this->artisan('photos:thumbnails', ['--collection' => 'invalid'])->assertFailed();
    }

    public function test_exif_orientations_are_applied_to_derivatives_and_original_jpeg_is_preserved(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('EXIF support is optional locally and enabled in CI.');
        }
        Storage::fake('local');
        $collection = $this->collection();
        $image = imagecreatetruecolor(40, 20);
        $colors = [[220, 40, 40], [40, 180, 40], [40, 40, 220], [220, 200, 40]];
        foreach ($colors as $index => $color) {
            $x = ($index % 2) * 20;
            $y = intdiv($index, 2) * 10;
            imagefilledrectangle($image, $x, $y, $x + 19, $y + 9, imagecolorallocate($image, ...$color));
        }
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $expectedCorner = [1 => 0, 2 => 1, 3 => 3, 4 => 2, 5 => 0, 6 => 2, 7 => 3, 8 => 1];
        foreach ($expectedCorner as $orientation => $colorIndex) {
            $photo = $this->photo($collection, 'jpg', 40, 20);
            $tiff = "II\x2a\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\x00\x00".pack('V', 0);
            $exif = "Exif\x00\x00".$tiff;
            $original = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
            Storage::disk('local')->put($photo->path, $original);
            app(PhotoThumbnail::class)->generate($photo->id);
            $thumbnail = imagecreatefromstring(Storage::disk('local')->get($photo->thumbnailFile()));
            $this->assertSame($orientation >= 5 ? [20, 40] : [40, 20], [imagesx($thumbnail), imagesy($thumbnail)]);
            $pixel = imagecolorsforindex($thumbnail, imagecolorat($thumbnail, intdiv(imagesx($thumbnail), 4), intdiv(imagesy($thumbnail), 4)));
            foreach (['red', 'green', 'blue'] as $channel => $name) {
                $this->assertEqualsWithDelta($colors[$colorIndex][$channel], $pixel[$name], 30);
            }
            imagedestroy($thumbnail);
            $this->assertSame($original, Storage::disk('local')->get($photo->path));
        }
    }

    public function test_transparent_png_flattens_onto_white_and_invalid_legacy_sources_are_not_decoded(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $photo = $this->photo($collection, 'png', 12, 12);
        $image = imagecreatetruecolor(12, 12);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $transparent = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put($photo->path, $transparent);
        app(PhotoThumbnail::class)->generate($photo->id);
        $thumbnail = imagecreatefromstring(Storage::disk('local')->get($photo->thumbnailFile()));
        $pixel = imagecolorsforindex($thumbnail, imagecolorat($thumbnail, 6, 6));
        $this->assertSame([255, 255, 255], [$pixel['red'], $pixel['green'], $pixel['blue']]);
        imagedestroy($thumbnail);
        foreach (['corrupt image', substr_replace($transparent, pack('NN', 5000, 5000), 16, 8)] as $invalid) {
            $legacy = $this->photo($collection);
            Storage::disk('local')->put($legacy->path, $invalid);
            try {
                app(PhotoThumbnail::class)->generate($legacy->id);
                $this->fail('Expected invalid source rejection.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Thumbnail source is not a supported bounded image.', $exception->getMessage());
            }
            Storage::disk('local')->assertMissing($legacy->thumbnailFile());
            $this->assertSame($invalid, Storage::disk('local')->get($legacy->path));
        }
    }

    public function test_failed_derivative_deletion_preserves_original_and_photo_metadata_for_retry(): void
    {
        Storage::fake('local');
        $photo = $this->photo($this->collection());
        app(PhotoThumbnail::class)->generate($photo->id);
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('delete')->with($photo->thumbnailFile())->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->actingAs(User::findOrFail($photo->uploadSession->user_id))->delete(route('photos.destroy', $photo))->assertStatus(503);
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        $disk->assertExists([$photo->path, $photo->thumbnailFile()]);
    }
}
