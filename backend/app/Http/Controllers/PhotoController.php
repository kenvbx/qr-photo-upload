<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
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

    public function destroy(Request $r, Photo $photo)
    {
        abort_unless($photo->uploadSession->user_id === $r->user()->id, 404);
        DB::transaction(function () use ($r, $photo) {
            $locked = Photo::lockForUpdate()->findOrFail($photo->id);
            if (! Storage::disk('local')->delete($locked->path)) {
                abort(503, 'Unable to remove the photo. Please retry.');
            }
            DB::table('audit_events')->insert(['user_id' => $r->user()->id, 'action' => 'photo.deleted', 'resource_id' => $locked->id, 'created_at' => now()]);
            $locked->delete();
        });

        return redirect()->route('collections.show', $photo->upload_session_id)->with('status','Photo deleted.');
    }
}
