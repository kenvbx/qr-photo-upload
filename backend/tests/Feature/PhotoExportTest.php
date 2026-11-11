<?php

namespace Tests\Feature;

use App\Jobs\BuildPhotoExport;
use App\Models\Photo;
use App\Models\PhotoExport;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PhotoExportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PhotoExportTest extends TestCase
{
    use RefreshDatabase;

    private function collection(): UploadSession
    {
        return UploadSession::create(['user_id' => User::factory()->create()->id, 'title' => 'Queued ZIP', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()]);
    }

    private function photo(UploadSession $collection, string $bytes = 'original image'): Photo
    {
        $path = 'photos/'.bin2hex(random_bytes(16)).'.png';
        Storage::disk('local')->put($path, $bytes);

        return $collection->photos()->create(['path' => $path, 'mime' => 'image/png', 'bytes' => strlen($bytes), 'width' => 40, 'height' => 30]);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'exports', '--once' => true, '--sleep' => 0, '--memory' => 256])->assertSuccessful();
    }

    public function test_request_returns_status_page_without_building_and_repeated_selection_reuses_one_job(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $second = $this->photo($collection);
        $this->actingAs(User::findOrFail($collection->user_id));
        $this->post(route('photos.selection', $collection), ['action' => 'download', 'photos' => [$first->id, $second->id]])->assertRedirect();
        $export = PhotoExport::firstOrFail();
        $this->assertSame('queued', $export->status);
        Storage::disk('local')->assertMissing($export->archiveFile());
        $this->post(route('photos.selection', $collection), ['action' => 'download', 'photos' => [$second->id, $first->id]])->assertRedirect(route('exports.show', $export));
        $this->assertDatabaseCount('photo_exports', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->get(route('exports.show', $export))->assertOk()->assertSee('Waiting to start');
        $this->getJson(route('exports.show', $export))->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.download_url', null);
        $this->get(route('exports.download', $export))->assertConflict();
        $this->work();
        $this->assertSame('ready', $export->fresh()->status);
        $this->getJson(route('exports.show', $export))->assertOk()->assertJsonPath('data.download_url', route('exports.download', $export));
        $bytes = Storage::disk('local')->get($export->archiveFile());
        app(PhotoExportBuilder::class)->build($export->id);
        $this->assertSame($bytes, Storage::disk('local')->get($export->archiveFile()));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_status_download_and_retry_require_owner_even_with_export_uuid(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $export = app(PhotoExportBuilder::class)->request($collection, [$this->photo($collection)->id]);
        foreach (['exports.show', 'exports.download'] as $route) {
            $this->get(route($route, $export))->assertRedirect(route('login'));
        }
        $this->post(route('exports.retry', $export))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        foreach (['exports.show', 'exports.download'] as $route) {
            $this->get(route($route, $export))->assertNotFound();
        }
        $this->post(route('exports.retry', $export))->assertNotFound();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_worker_failure_cleans_partial_zip_retries_and_records_exhaustion_then_owner_can_recover(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $second = $this->photo($collection, 'second');
        $export = app(PhotoExportBuilder::class)->request($collection, [$first->id, $second->id]);
        Storage::disk('local')->delete($second->path);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->work();
            Storage::disk('local')->assertMissing([$export->archiveFile(), $export->temporaryFile()]);
            if ($attempt < 3) {
                $this->assertSame('queued', $export->fresh()->status);
                $this->assertDatabaseHas('jobs', ['queue' => 'exports', 'attempts' => $attempt]);
                $this->assertGreaterThan(now()->timestamp, DB::table('jobs')->value('available_at'));
                DB::table('jobs')->update(['available_at' => 0]);
            }
        }
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertDatabaseCount('failed_jobs', 1);
        Storage::disk('local')->put($second->path, 'second');
        $this->actingAs(User::findOrFail($collection->user_id))->post(route('exports.retry', $export))->assertRedirect(route('exports.show', $export));
        $this->assertSame('queued', $export->fresh()->status);
        $this->post(route('exports.retry', $export))->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->get(route('exports.download', $export))->assertOk()->assertDownload();
        (new BuildPhotoExport($export->id))->failed(new RuntimeException('stale failure'));
        $this->assertSame('ready', $export->fresh()->status);
    }

    public function test_deleted_source_before_build_does_not_create_partial_zip_and_ready_download_is_revoked_after_deletion(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $photo = $this->photo($collection);
        $export = app(PhotoExportBuilder::class)->request($collection, [$photo->id]);
        $this->actingAs(User::findOrFail($collection->user_id));
        $this->work();
        $this->delete(route('photos.destroy', $photo))->assertRedirect();
        $this->get(route('exports.download', $export))->assertGone();
        $this->getJson(route('exports.show', $export))->assertJsonPath('data.status', 'failed')->assertJsonPath('data.download_url', null);
        $this->post(route('exports.retry', $export))->assertRedirect()->assertSessionHasErrors('export');
        $photo = $this->photo($collection);
        $waiting = app(PhotoExportBuilder::class)->request($collection, [$photo->id]);
        $this->delete(route('photos.destroy', $photo))->assertRedirect();
        try {
            app(PhotoExportBuilder::class)->build($waiting->id);
            $this->fail('Expected deleted source rejection.');
        } catch (RuntimeException $exception) {
            Storage::disk('local')->assertMissing($waiting->archiveFile());
        }
    }

    public function test_expiry_blocks_download_and_prune_removes_archives_temporary_files_and_late_jobs(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $export = app(PhotoExportBuilder::class)->request($collection, [$this->photo($collection)->id]);
        $this->work();
        $export->update(['expires_at' => now()->subSecond()]);
        Storage::disk('local')->put($export->temporaryFile(), 'abandoned');
        $this->actingAs(User::findOrFail($collection->user_id))->get(route('exports.download', $export))->assertGone();
        $this->getJson(route('exports.show', $export))->assertJsonPath('data.status', 'expired');
        $this->artisan('exports:prune')->expectsOutput('1 expired exports removed.')->assertSuccessful();
        Storage::disk('local')->assertMissing([$export->archiveFile(), $export->temporaryFile()]);
        $this->assertDatabaseMissing('photo_exports', ['id' => $export->id]);
        (new BuildPhotoExport($export->id))->handle(app(PhotoExportBuilder::class));
        Storage::disk('local')->assertMissing($export->archiveFile());
    }

    public function test_expired_or_missing_archive_can_be_rebuilt_and_unexpired_exports_survive_prune(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $export = app(PhotoExportBuilder::class)->request($collection, [$this->photo($collection)->id]);
        $this->work();
        $this->actingAs(User::findOrFail($collection->user_id));
        foreach (['expired', 'missing'] as $failure) {
            if ($failure === 'expired') {
                $export->update(['expires_at' => now()->subSecond()]);
            } else {
                Storage::disk('local')->delete($export->archiveFile());
            }
            $this->post(route('exports.retry', $export))->assertRedirect();
            $this->work();
            $this->assertSame('ready', $export->fresh()->status);
            $this->assertTrue($export->fresh()->expires_at->isFuture());
        }
        $this->artisan('exports:prune')->expectsOutput('0 expired exports removed.')->assertSuccessful();
        Storage::disk('local')->assertExists($export->archiveFile());
    }

    public function test_queue_insertion_failure_rolls_back_export_request(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $photo = $this->photo($collection);
        $queue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->shouldReceive('push')->once()->andThrow(new RuntimeException('Queue unavailable'));
        Queue::shouldReceive('connection')->with('database')->andReturn($queue);
        try {
            app(PhotoExportBuilder::class)->request($collection, [$photo->id]);
            $this->fail('Expected queue insertion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('photo_exports', 0);
        $this->assertDatabaseCount('jobs', 0);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_publish_failure_cleans_archive_and_prune_failure_preserves_record_for_retry(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $photo = $this->photo($collection);
        $export = app(PhotoExportBuilder::class)->request($collection, [$photo->id]);
        $disk = Storage::disk('local');
        $manager = Storage::getFacadeRoot();
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('move')->with($export->temporaryFile(), $export->archiveFile())->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->work();
        $this->assertSame('queued', $export->fresh()->status);
        $disk->assertMissing([$export->temporaryFile(), $export->archiveFile()]);
        $disk->assertExists($photo->path);
        Storage::swap($manager);
        $export->update(['expires_at' => now()->subSecond()]);
        $disk->put($export->temporaryFile(), 'interrupted');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('delete')->with([$export->archiveFile(), $export->temporaryFile()])->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        try {
            $this->artisan('exports:prune')->run();
            $this->fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to remove expired ZIP files.', $exception->getMessage());
        }
        $this->assertDatabaseHas('photo_exports', ['id' => $export->id]);
        $disk->assertExists($export->temporaryFile());
    }
}
