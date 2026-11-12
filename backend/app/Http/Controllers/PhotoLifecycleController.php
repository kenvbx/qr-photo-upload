<?php

namespace App\Http\Controllers;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\Photo;
use App\Models\UploadSession;
use App\Services\PhotoRemoval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PhotoLifecycleController extends Controller
{
    public function index(Request $request, UploadSession $collection): View
    {
        abort_unless($collection->user_id === $request->user()->id, 404);
        $photos = $collection->photos()->onlyTrashed()->latest('deleted_at')->paginate(24);

        return view('trash', compact('collection', 'photos'));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $photo = Photo::withTrashed()->findOrFail($id);
        abort_unless($photo->uploadSession->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($photo, $request) {
            $locked = Photo::lockForMutation($photo->id, true);
            abort_unless($locked, 404);
            if (! $locked->trashed()) {
                return;
            }
            abort_if($locked->deleted_at->lte(now()->subDays(30)) || $locked->delete_after?->isPast(), 410, 'This photo has reached its deletion deadline.');
            abort_unless(Storage::disk($locked->disk)->exists($locked->path), 409, 'The original file is unavailable.');
            $locked->moderation_status = 'pending';
            $locked->restore();
            if ($locked->thumbnail_status !== 'ready' || ! Storage::disk($locked->disk)->exists($locked->thumbnailFile())) {
                $locked->update(['thumbnail_status' => 'pending']);
                GeneratePhotoThumbnail::dispatch($locked->id);
            }
            DB::table('audit_events')->insert(['user_id' => $request->user()->id, 'action' => 'photo.restored', 'resource_id' => $locked->id, 'created_at' => now()]);
        });

        return redirect()->route('trash.index', $photo->upload_session_id)->with('status', 'Photo restored for review.');
    }

    public function purge(Request $request, int $id, PhotoRemoval $removal): RedirectResponse
    {
        $photo = Photo::onlyTrashed()->findOrFail($id);
        abort_unless($photo->uploadSession->user_id === $request->user()->id, 404);
        abort_unless($removal->remove($photo, $request->user()->id, true), 503, 'Unable to permanently delete this photo.');

        return redirect()->route('trash.index', $photo->upload_session_id)->with('status', 'Photo permanently deleted.');
    }

    public function moderate(Request $request, UploadSession $collection): RedirectResponse
    {
        abort_unless($collection->user_id === $request->user()->id, 404);
        $data = $request->validate(['photos' => ['required', 'array', 'min:1', 'max:24'], 'photos.*' => ['integer', 'distinct', 'min:1'], 'decision' => ['required', Rule::in(['approved', 'rejected', 'pending'])]]);
        DB::transaction(function () use ($data, $collection, $request) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('photos')->where('upload_session_id', $collection->id)->update(['id' => DB::raw('id')]);
            }
            $photos = $collection->photos()->whereIn('id', $data['photos'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($photos->count() === count($data['photos']), 404);
            foreach ($photos as $photo) {
                $photo->update(['moderation_status' => $data['decision']]);
                DB::table('audit_events')->insert(['user_id' => $request->user()->id, 'action' => 'photo.'.$data['decision'], 'resource_id' => $photo->id, 'created_at' => now()]);
            }
        });

        return redirect()->route('collections.show', $collection)->with('status', 'Photo review updated. Only approved photos can appear in the shared album.');
    }
}
