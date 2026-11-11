<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\PhotoUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class GuestUploadController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active(), 410, 'This upload link has expired or closed. Please ask the organizer for a new link.');

        if (! $this->unlocked($request, $collection)) {
            return view('unlock', compact('collection'));
        }

        return view('upload', compact('collection'));
    }

    private function unlocked(Request $request, UploadSession $collection): bool
    {
        return ! $collection->password_hash || hash_equals($collection->password_hash, (string) $request->session()->get('upload_grants.'.$collection->id, ''));
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($collection->active(), 410);
        $data = $request->validate(['password' => ['required', 'string', 'max:128']]);
        if ($collection->password_hash && ! Hash::check($data['password'], $collection->password_hash)) {
            return back()->withErrors(['password' => 'Incorrect password. Please try again.']);
        }
        $request->session()->regenerate();
        $request->session()->put('upload_grants.'.$collection->id, $collection->password_hash);

        return redirect()->route('upload', $token);
    }

    public function store(Request $request, string $token, PhotoUpload $uploads): JsonResponse|RedirectResponse
    {
        $collection = UploadSession::where('token', $token)->firstOrFail();
        abort_unless($this->unlocked($request, $collection), 403, 'Unlock this upload link before sending photos.');
        // Keep native form submissions compatible; JSON clients must supply an upload ID.
        if (! $collection->active() && ! $request->filled('upload_key')) {
            abort(410, 'This upload link has expired or closed.');
        }
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'upload_key' => [$request->expectsJson() ? 'required' : 'nullable', 'uuid'],
        ]);
        $result = $uploads->store($collection, $request->file('photos'), isset($data['upload_key']) ? strtolower($data['upload_key']) : null, $request->session()->get('upload_grants.'.$collection->id));
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
