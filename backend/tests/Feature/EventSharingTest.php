<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PhotoRemoval;
use App\Services\PhotoThumbnail;
use App\Services\PhotoUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventSharingTest extends TestCase
{
    use RefreshDatabase;

    private function collection(array $attributes = []): UploadSession
    {
        return UploadSession::create($attributes + ['user_id' => User::factory()->create()->id, 'title' => 'Our celebration', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDays(3)]);
    }

    private function photo(UploadSession $c, int $size = 50): Photo
    {
        app(PhotoUpload::class)->store($c, [UploadedFile::fake()->image('memory.png', $size, 40)]);

        return $c->photos()->latest('id')->firstOrFail();
    }

    private function settings(array $data = []): array
    {
        return $data + ['brand_color' => '#285c46', 'share_days' => '7'];
    }

    public function test_sharing_is_opt_in_and_only_approved_ready_previews_are_public(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        app(PhotoThumbnail::class)->generate($photo->id);
        $this->assertFalse($c->fresh()->shared());
        $this->assertNull($c->fresh()->share_token);
        $this->actingAs(User::findOrFail($c->user_id))->post(route('event.update', $c), $this->settings(['share_enabled' => 1]))->assertRedirect();
        $token = $c->fresh()->share_token;
        $this->assertNotSame($c->token, $token);
        $this->getJson('/a/'.$token)->assertOk()->assertJsonCount(0, 'photos');
        $this->get('/a/'.$token.'/photos/'.$photo->id)->assertNotFound();
        $this->post(route('photos.moderate', $c), ['photos' => [$photo->id], 'decision' => 'approved'])->assertRedirect();
        $this->getJson('/a/'.$token)->assertOk()->assertJsonPath('photos.0.id', $photo->id)->assertDontSee('photos/1/');
        $this->get('/a/'.$token.'/photos/'.$photo->id)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('image/png', $photo->mime);
        $this->post(route('photos.moderate', $c), ['photos' => [$photo->id], 'decision' => 'rejected'])->assertRedirect();
        $this->get('/a/'.$token.'/photos/'.$photo->id)->assertNotFound();
        $this->getJson('/a/'.$token)->assertJsonCount(0, 'photos');
    }

    public function test_album_disable_rotate_expiry_and_foreign_photo_revoke_all_media(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        app(PhotoThumbnail::class)->generate($photo->id);
        $photo->update(['moderation_status' => 'approved']);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post(route('event.update', $c), $this->settings(['share_enabled' => 1, 'logo' => UploadedFile::fake()->image('logo.png')]))->assertRedirect();
        $token = $c->fresh()->share_token;
        $this->get('/a/'.$token.'/logo')->assertOk();
        $other = $this->photo($this->collection(), 51);
        $this->get('/a/'.$token.'/photos/'.$other->id)->assertNotFound();
        $this->post(route('event.update', $c), $this->settings(['share_enabled' => 1, 'rotate_share' => 1]))->assertRedirect();
        $next = $c->fresh()->share_token;
        $this->assertNotSame($token, $next);
        foreach (['/a/'.$token, '/a/'.$token.'/logo', '/a/'.$token.'/photos/'.$photo->id] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post(route('event.update', $c), $this->settings())->assertRedirect();
        $this->get('/a/'.$next)->assertNotFound();
        $c->refresh()->update(['share_enabled' => true, 'share_expires_at' => now()->subSecond()]);
        $this->get('/a/'.$next.'/photos/'.$photo->id)->assertNotFound();
    }

    public function test_branding_reencodes_logo_validates_input_and_escapes_welcome(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post(route('event.update', $c), $this->settings(['brand_color' => '#ffffff', 'welcome_message' => '<script>alert(1)</script>', 'logo' => UploadedFile::fake()->image('camera.jpg', 800, 400)]))->assertRedirect();
        $c->refresh();
        $this->assertSame('#000000', $c->brandForeground());
        $this->assertNotNull($c->logo_path);
        $logo = Storage::disk('local')->get($c->logo_path);
        $this->assertSame('image/png', getimagesizefromstring($logo)['mime']);
        $this->assertSame(512, getimagesizefromstring($logo)[0]);
        $this->get('/u/'.$c->token)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('#ffffff');
        $this->get('/u/'.$c->token.'/logo')->assertOk();
        $old = $c->logo_path;
        $this->post(route('event.update', $c), $this->settings(['remove_logo' => 1]))->assertRedirect();
        Storage::disk('local')->assertMissing($old);
        $this->post(route('event.update', $c), $this->settings(['brand_color' => 'red; background:url(bad)', 'welcome_message' => str_repeat('x', 501), 'share_days' => 31, 'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')]))->assertSessionHasErrors(['brand_color', 'welcome_message', 'share_days', 'logo']);
    }

    public function test_trash_hides_photo_and_zip_restore_requires_review_and_quota_includes_trash(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        app(PhotoThumbnail::class)->generate($photo->id);
        $photo->update(['moderation_status' => 'approved']);
        $c->update(['share_token' => str_repeat('f', 64), 'share_enabled' => true, 'share_expires_at' => now()->addDay(), 'quota_bytes' => $photo->bytes]);
        $this->actingAs(User::findOrFail($c->user_id))->delete(route('photos.destroy', $photo))->assertRedirect();
        $this->assertSoftDeleted($photo);
        Storage::disk('local')->assertExists([$photo->path, $photo->thumbnailFile()]);
        $this->get(route('photos.show', $photo))->assertNotFound();
        $this->get('/a/'.$c->share_token.'/photos/'.$photo->id)->assertNotFound();
        $this->get(route('trash.index', $c))->assertOk()->assertSee('Photo '.$photo->id);
        $this->postJson('/u/'.$c->token, ['photos' => [UploadedFile::fake()->image('new.png', 51, 40)], 'upload_key' => fake()->uuid()])->assertUnprocessable();
        $this->post(route('trash.restore', $photo->id))->assertRedirect();
        $this->assertNotSoftDeleted($photo);
        $this->assertSame('pending', $photo->fresh()->moderation_status);
        $this->get('/a/'.$c->share_token.'/photos/'.$photo->id)->assertNotFound();
        $this->delete(route('photos.destroy', $photo))->assertRedirect();
        $this->delete(route('trash.purge', $photo->id))->assertRedirect();
        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        Storage::disk('local')->assertMissing([$photo->path, $photo->thumbnailFile()]);
    }

    public function test_retention_and_thirty_day_trash_cleanup_prevent_late_restore(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        $photo->delete();
        $photo->deleted_at = now()->subDays(30);
        $photo->save();
        $this->actingAs(User::findOrFail($c->user_id))->post(route('trash.restore', $photo->id))->assertGone();
        $this->artisan('photos:prune')->assertSuccessful();
        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $current = $this->photo($c, 52);
        $current->delete();
        $current->delete_after = now()->subSecond();
        $current->save();
        $this->post(route('trash.restore', $current->id))->assertGone();
        $this->artisan('photos:prune')->assertSuccessful();
        $this->assertDatabaseMissing('photos', ['id' => $current->id]);
    }

    public function test_images_and_live_polling_do_not_replace_form_validation_redirect_target(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get(route('collections.show', $c))->assertOk();
        $this->get(route('photos.thumbnail', $photo))->assertOk();
        $this->getJson(route('collections.show', $c))->assertOk();
        $this->post(route('event.update', $c), $this->settings(['brand_color' => 'invalid']))->assertRedirect(route('collections.show', $c))->assertSessionHasErrors('brand_color');
    }

    public function test_stale_purge_or_prune_cannot_remove_a_restored_photo(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        $photo->delete();
        $stale = Photo::withTrashed()->findOrFail($photo->id);
        $photo->restore();
        $removal = app(PhotoRemoval::class);
        $this->assertFalse($removal->remove($stale, $c->user_id, true));
        $this->assertFalse($removal->remove($stale, $c->user_id, false, true));
        $this->assertNotSoftDeleted($photo);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_other_owners_and_guests_cannot_change_event_moderation_or_trash(): void
    {
        Storage::fake('local');
        $c = $this->collection();
        $photo = $this->photo($c);
        $this->post(route('event.update', $c), $this->settings())->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->post(route('event.update', $c), $this->settings())->assertNotFound();
        $this->post(route('photos.moderate', $c), ['photos' => [$photo->id], 'decision' => 'approved'])->assertNotFound();
        $this->get(route('trash.index', $c))->assertNotFound();
        $photo->delete();
        $this->post(route('trash.restore', $photo->id))->assertNotFound();
        $this->delete(route('trash.purge', $photo->id))->assertNotFound();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post(route('photos.moderate', $c), ['photos' => [$photo->id], 'decision' => 'approved'])->assertNotFound();
        $this->post(route('photos.moderate', $c), ['photos' => [$photo->id], 'decision' => 'publish-all'])->assertSessionHasErrors('decision');
    }
}
