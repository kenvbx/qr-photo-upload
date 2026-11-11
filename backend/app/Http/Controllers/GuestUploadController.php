<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\PhotoUpload;
use Illuminate\Http\Request;

class GuestUploadController extends Controller
{
    public function show(string $token)
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active(), 410, 'This upload link has expired or closed. Please ask the organizer for a new link.');

        return view('upload', compact('collection'));
    }

    public function store(Request $r, string $token, PhotoUpload $uploads)
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active(), 410, 'This upload link has expired or closed.');
        $r->validate(['photos' => ['required', 'array', 'min:1', 'max:10'], 'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240']]);
        $count = $uploads->store($collection, $r->file('photos'));

        return redirect()->route('upload', $token)->with('status', "Thank you! {$count} photo(s) uploaded successfully.");
    }
}
