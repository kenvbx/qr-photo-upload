<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function update(Request $request, UploadSession $collection): RedirectResponse
    {
        abort_unless($collection->user_id === $request->user()->id, 404);
        $data = $request->validate(['welcome_message' => ['nullable', 'string', 'max:500'], 'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'logo' => ['nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'], 'remove_logo' => ['nullable', 'boolean'], 'share_enabled' => ['nullable', 'boolean'], 'share_days' => ['required', 'integer', 'min:1', 'max:30'], 'rotate_share' => ['nullable', 'boolean']]);
        $newLogo = null;
        $oldLogo = null;
        if ($file = $request->file('logo')) {
            $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            abort_unless($source, 422, 'Unable to decode logo.');
            try {
                $scale = min(1, 512 / max(imagesx($source), imagesy($source)));
                $canvas = imagecreatetruecolor(max(1, (int) (imagesx($source) * $scale)), max(1, (int) (imagesy($source) * $scale)));
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), imagesx($source), imagesy($source));
                ob_start();
                imagepng($canvas);
                $bytes = ob_get_clean();
                $newLogo = 'branding/'.$collection->id.'/'.Str::uuid().'.png';
                try {
                    abort_unless(Storage::disk('local')->put($newLogo, $bytes), 503, 'Unable to save logo.');
                } catch (\Throwable $error) {
                    Storage::disk('local')->delete($newLogo);
                    throw $error;
                }
            } finally {
                imagedestroy($source);
                if (isset($canvas)) {
                    imagedestroy($canvas);
                }
            }
        }
        try {
            DB::transaction(function () use ($request, $collection, $data, $newLogo, &$oldLogo) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('upload_sessions')->where('id', $collection->id)->update(['id' => DB::raw('id')]);
                }
                $locked = UploadSession::lockForUpdate()->findOrFail($collection->id);
                $values = ['welcome_message' => $data['welcome_message'] ?? null, 'brand_color' => strtolower($data['brand_color']), 'share_enabled' => $request->boolean('share_enabled')];
                if ($request->boolean('share_enabled')) {
                    if (! $locked->share_token || $request->boolean('rotate_share')) {
                        $values['share_token'] = bin2hex(random_bytes(32));
                    }
                    $values['share_expires_at'] = now()->addDays((int) $data['share_days']);
                }
                if ($newLogo || $request->boolean('remove_logo')) {
                    $oldLogo = $locked->logo_path;
                    $values['logo_path'] = $newLogo;
                }
                $locked->update($values);
                DB::table('audit_events')->insert(['user_id' => $request->user()->id, 'action' => 'event.settings_updated', 'resource_id' => $locked->id, 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            if ($newLogo) {
                Storage::disk('local')->delete($newLogo);
            } throw $e;
        }
        if ($oldLogo) {
            Storage::disk('local')->delete($oldLogo);
        }

        return redirect()->route('collections.show', $collection)->with('status', 'Event appearance and sharing updated.');
    }

    public function logo(Request $request, UploadSession $collection): mixed
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        return $this->logoResponse($collection);
    }

    public function guestLogo(Request $request, string $token): mixed
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active() && (! $collection->password_hash || hash_equals($collection->password_hash, (string) $request->session()->get('upload_grants.'.$collection->id, ''))), 404);

        return $this->logoResponse($collection);
    }

    public function logoResponse(UploadSession $collection): mixed
    {
        abort_unless($collection->logo_path && Storage::disk('local')->exists($collection->logo_path), 404);

        return Storage::disk('local')->response($collection->logo_path, null, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }
}
