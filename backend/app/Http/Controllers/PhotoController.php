<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Services\PhotoRemoval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function thumbnail(Request $request, Photo $photo)
    {
        abort_unless($photo->uploadSession->user_id === $request->user()->id, 404);
        $disk = Storage::disk('local');
        $headers = ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($photo->thumbnail_status === 'ready' && $disk->exists($photo->thumbnailFile())) {
            return $disk->response($photo->thumbnailFile(), null, $headers);
        }

        return response()->view('thumbnail-placeholder', ['label' => $photo->thumbnail_status === 'pending' ? 'Preview processing' : 'Preview unavailable'], 200, array_merge($headers, ['Content-Type' => 'image/svg+xml']));
    }

    public function show(Request $r, Photo $photo)
    {
        abort_unless($photo->uploadSession->user_id === $r->user()->id, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($photo->path), 404);
        $headers = ['Content-Type' => $photo->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($r->boolean('download')) {
            return $disk->download($photo->path, 'photo-'.$photo->id.'.'.pathinfo($photo->path, PATHINFO_EXTENSION), $headers);
        }

        return $disk->response($photo->path, null, $headers);
    }

    public function destroy(Request $r, Photo $photo, PhotoRemoval $removal)
    {
        abort_unless($photo->uploadSession->user_id === $r->user()->id, 404);
        abort_unless($removal->remove($photo, $r->user()->id), 503, 'Unable to remove the photo. Please retry.');

        return redirect()->route('collections.show', $photo->upload_session_id)->with('status', 'Photo deleted.');
    }
}
