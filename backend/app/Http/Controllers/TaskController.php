<?php

namespace App\Http\Controllers;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\Photo;
use App\Models\PhotoExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $photos = Photo::whereHas('uploadSession', fn ($q) => $q->where('user_id', $request->user()->id))->with('uploadSession')->latest('updated_at')->latest('id')->paginate(30, ['*'], 'thumbnails');
        $exports = PhotoExport::whereHas('uploadSession', fn ($q) => $q->where('user_id', $request->user()->id))->with('uploadSession')->latest()->paginate(15, ['*'], 'exports');
        $html = view('task-list', compact('photos', 'exports'))->render();
        if ($request->expectsJson()) {
            return response()->json(['html' => $html, 'revision' => hash('sha256', $html)])->header('Cache-Control', 'private, no-store');
        }

        return view('tasks', compact('html'));
    }

    public function retry(Request $request, Photo $photo): RedirectResponse
    {
        abort_unless($photo->uploadSession->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($photo) {
            $locked = Photo::lockForMutation($photo->id);
            if (! $locked || $locked->thumbnail_status === 'pending') {
                return;
            }
            if ($locked->thumbnail_status === 'ready' && Storage::disk($locked->disk)->exists($locked->thumbnailFile())) {
                return;
            }
            $locked->update(['thumbnail_status' => 'pending']);
            GeneratePhotoThumbnail::dispatch($locked->id);
        });

        return back()->with('status', 'Thumbnail queued, or already available.');
    }
}
