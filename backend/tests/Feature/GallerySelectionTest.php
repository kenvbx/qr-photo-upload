<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoExport;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PhotoExportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PharData;
use Tests\TestCase;

class GallerySelectionTest extends TestCase
{
    use RefreshDatabase;

    private function collection(?User $user = null): UploadSession
    {
        return UploadSession::create(['user_id' => ($user ?? User::factory()->create())->id, 'title' => 'Gallery', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()]);
    }

    private function photo(UploadSession $collection, string $content = 'private image'): Photo
    {
        $path = 'photos/'.bin2hex(random_bytes(16)).'.png';
        Storage::disk('local')->put($path, $content);

        return $collection->photos()->create(['path' => $path, 'mime' => 'image/png', 'bytes' => strlen($content), 'width' => 40, 'height' => 30]);
    }

    public function test_owner_downloads_only_selected_photos_in_private_zip_with_original_bytes(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection, 'first original');
        $second = $this->photo($collection, 'second original');
        $this->photo($collection, 'unselected');
        $response = $this->actingAs(User::findOrFail($collection->user_id))->post(route('photos.selection', $collection), ['action' => 'download', 'photos' => [$first->id, $second->id]])
            ->assertRedirect();
        $export = PhotoExport::firstOrFail();
        $this->assertSame('queued', $export->status);
        $this->assertDatabaseCount('jobs', 1);
        app(PhotoExportBuilder::class)->build($export->id);
        $response = $this->get(route('exports.download', $export))->assertOk()->assertDownload('collection-'.$collection->id.'-selected.zip')->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $archive = new PharData($path);
            $this->assertCount(2, $archive);
            $this->assertSame('first original', $archive['photo-'.$first->id.'.png']->getContent());
            $this->assertSame('second original', $archive['photo-'.$second->id.'.png']->getContent());
            $this->assertFileExists($path);
        } finally {
            Storage::disk('local')->delete($export->archiveFile());
        }
        $this->assertDatabaseCount('photos', 3);
    }

    public function test_owner_deletes_only_selected_photos_and_records_each_success(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $second = $this->photo($collection);
        $retained = $this->photo($collection);
        $this->actingAs(User::findOrFail($collection->user_id))->post(route('photos.selection', $collection), ['action' => 'delete', 'photos' => [$first->id, $second->id]])
            ->assertRedirect(route('collections.show', $collection))->assertSessionHas('status', '2 photos deleted.');
        Storage::disk('local')->assertMissing([$first->path, $second->path]);
        Storage::disk('local')->assertExists($retained->path);
        $this->assertDatabaseCount('photos', 1);
        foreach ([$first, $second] as $photo) {
            $this->assertDatabaseHas('audit_events', ['action' => 'photo.deleted', 'resource_id' => $photo->id, 'user_id' => $collection->user_id]);
        }
    }

    public function test_guest_and_other_owner_cannot_act_on_selection(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $photo = $this->photo($collection);
        foreach (['download', 'delete'] as $action) {
            $this->post(route('photos.selection', $collection), ['action' => $action, 'photos' => [$photo->id]])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach (['download', 'delete'] as $action) {
            $this->post(route('photos.selection', $collection), ['action' => $action, 'photos' => [$photo->id]])->assertNotFound();
        }
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_mixed_collection_or_stale_selection_rejects_whole_request_before_any_action(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $other = $this->photo($this->collection(User::findOrFail($collection->user_id)));
        $foreign = $this->photo($this->collection());
        $this->actingAs(User::findOrFail($collection->user_id));
        foreach (['download', 'delete'] as $action) {
            foreach ([$other->id, $foreign->id, 99999] as $id) {
                $this->post(route('photos.selection', $collection), ['action' => $action, 'photos' => [$first->id, $id]])->assertNotFound();
            }
        }
        $this->assertDatabaseCount('photos', 3);
        $this->assertDatabaseCount('audit_events', 0);
        Storage::disk('local')->assertExists($first->path);
    }

    public function test_selection_requires_supported_action_and_one_to_twenty_four_distinct_photo_ids(): void
    {
        $collection = $this->collection();
        $this->actingAs(User::findOrFail($collection->user_id));
        $this->postJson(route('photos.selection', $collection), [])->assertUnprocessable()->assertJsonValidationErrors(['action', 'photos']);
        $this->postJson(route('photos.selection', $collection), ['action' => 'publish', 'photos' => [1]])->assertUnprocessable()->assertJsonValidationErrors('action');
        foreach ([[], range(1, 25), [1, 1], ['invalid'], [-1]] as $ids) {
            $this->postJson(route('photos.selection', $collection), ['action' => 'download', 'photos' => $ids])->assertUnprocessable();
        }
    }

    public function test_missing_file_download_fails_without_silently_returning_partial_archive(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $missing = $this->photo($collection);
        Storage::disk('local')->delete($missing->path);
        $this->actingAs(User::findOrFail($collection->user_id))->from(route('collections.show', $collection))->post(route('photos.selection', $collection), ['action' => 'download', 'photos' => [$first->id, $missing->id]])
            ->assertRedirect(route('collections.show', $collection))->assertSessionHasErrors(['photos' => 'A selected file is unavailable. Refresh the gallery and choose again.']);
    }

    public function test_delete_failure_preserves_failed_metadata_and_reports_partial_success(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $failed = $this->photo($collection);
        $success = $this->photo($collection);
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('delete')->with($failed->path)->once()->andReturnFalse();
        $mock->shouldReceive('delete')->with($success->path)->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->actingAs(User::findOrFail($collection->user_id))->post(route('photos.selection', $collection), ['action' => 'delete', 'photos' => [$failed->id, $success->id]])
            ->assertRedirect(route('collections.show', $collection))->assertSessionHas('status', '1 photo deleted.')->assertSessionHasErrors('photos');
        $this->assertDatabaseHas('photos', ['id' => $failed->id]);
        $this->assertDatabaseMissing('photos', ['id' => $success->id]);
        $this->assertDatabaseCount('audit_events', 1);
        $disk->assertExists($failed->path);
    }

    public function test_archive_read_failure_returns_actionable_error_and_keeps_original_photos(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $first = $this->photo($collection);
        $second = $this->photo($collection);
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('path')->andReturnUsing(fn ($path) => $disk->path($path === $second->path ? 'unavailable.png' : $path));
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->actingAs(User::findOrFail($collection->user_id))->post(route('photos.selection', $collection), ['action' => 'download', 'photos' => [$first->id, $second->id]])->assertRedirect();
        $export = PhotoExport::firstOrFail();
        try {
            app(PhotoExportBuilder::class)->build($export->id);
            $this->fail('Expected archive read failure.');
        } catch (\RuntimeException $exception) {
            $disk->assertMissing([$export->archiveFile(), $export->temporaryFile()]);
        }
        $this->assertDatabaseCount('photos', 2);
        $disk->assertExists([$first->path, $second->path]);
    }

    public function test_gallery_pagination_has_stable_photo_order_when_upload_timestamps_match(): void
    {
        Storage::fake('local');
        $collection = $this->collection();
        $this->freezeTime();
        $ids = [];
        for ($index = 0; $index < 25; $index++) {
            $ids[] = $this->photo($collection)->id;
        }
        $this->actingAs(User::findOrFail($collection->user_id));
        $this->get(route('collections.show', $collection))->assertOk()->assertViewHas('photos', fn ($photos) => $photos->pluck('id')->all() === array_reverse(array_slice($ids, 1)));
        $this->get(route('collections.show', [$collection, 'page' => 2]))->assertOk()->assertViewHas('photos', fn ($photos) => $photos->pluck('id')->all() === [$ids[0]]);
    }
}
