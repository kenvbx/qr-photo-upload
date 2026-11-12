<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AlbumController extends Controller
{
    private function collection(string $token): UploadSession
    {
        $collection = UploadSession::where('share_token', $token)->firstOrFail();
        abort_unless($collection->shared(), 404);

        return $collection;
    }

    public function show(Request $request, string $token): mixed
    {
        $collection = $this->collection($token);
        $photos = $collection->photos()->where('moderation_status', 'approved')->where('thumbnail_status', 'ready')->latest()->latest('id')->paginate(24);
        if ($request->expectsJson()) {
            return response()->json(['photos' => $photos->map(fn ($photo) => ['id' => $photo->id, 'url' => route('album.photo', [$token, $photo->id])])->values(), 'next_page_url' => $photos->nextPageUrl()]);
        }

        return view('album', compact('collection', 'photos'));
    }

    public function photo(string $token, int $id): mixed
    {
        $collection = $this->collection($token);
        $photo = $collection->photos()->where('moderation_status', 'approved')->where('thumbnail_status', 'ready')->findOrFail($id);
        $disk = Storage::disk($photo->disk);
        abort_unless($disk->exists($photo->thumbnailFile()), 404);

        return $disk->response($photo->thumbnailFile(), null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    public function logo(string $token, EventController $events): mixed
    {
        return $events->logoResponse($this->collection($token));
    }
}
