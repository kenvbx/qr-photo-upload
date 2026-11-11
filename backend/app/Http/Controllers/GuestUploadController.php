<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\PhotoUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestUploadController extends Controller
{
    public function show(string $token): View
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active(), 410, 'This upload link has expired or closed. Please ask the organizer for a new link.');

        return view('upload', compact('collection'));
    }

    public function store(Request $request, string $token, PhotoUpload $uploads): JsonResponse|RedirectResponse
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        // Keep native form submissions compatible; JSON clients must supply an upload ID.
        if (! $collection->active() && ! $request->filled('upload_key')) {
            abort(410, 'This upload link has expired or closed.');
        }
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'upload_key' => [$request->expectsJson() ? 'required' : 'nullable', 'uuid'],
        ]);
        $result = $uploads->store($collection, $request->file('photos'), isset($data['upload_key']) ? strtolower($data['upload_key']) : null);
        if ($request->expectsJson()) {
            return response()->json(['data' => $result]);
        }
        $message = "Thank you! {$result['uploaded']} photo(s) uploaded successfully.";
        if ($result['duplicates'] > 0) {
            $message .= " {$result['duplicates']} already in this collection.";
        }

        return redirect()->route('upload', $token)->with('status', $message);
    }
}
