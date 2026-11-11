<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPhotoExport;
use App\Models\PhotoExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PhotoExportController extends Controller
{
    public function show(Request $request, PhotoExport $export): Response|JsonResponse
    {
        $this->authorizeOwner($request, $export);
        $state = $this->state($export);
        $data = ['status' => $state, 'count' => count($export->photo_ids), 'bytes' => $export->bytes, 'download_url' => $state === 'ready' ? route('exports.download', $export) : null];
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($request->expectsJson()) {
            return response()->json(['data' => $data])->withHeaders($headers);
        }

        return response()->view('exports.show', compact('export', 'data'), 200, $headers);
    }

    public function download(Request $request, PhotoExport $export): BinaryFileResponse
    {
        $this->authorizeOwner($request, $export);
        abort_if($export->expires_at->isPast(), 410, 'This ZIP has expired.');
        abort_unless($export->status === 'ready' && Storage::disk('local')->exists($export->archiveFile()), 409, 'ZIP is not ready.');
        abort_unless($this->sourcesExist($export), 410, 'A selected photo was removed. Choose again from the gallery.');

        return response()->download(Storage::disk('local')->path($export->archiveFile()), 'collection-'.$export->upload_session_id.'-selected.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function retry(Request $request, PhotoExport $export): RedirectResponse
    {
        $this->authorizeOwner($request, $export);

        return DB::transaction(function () use ($export) {
            $locked = PhotoExport::lockForMutation($export->id);
            abort_unless($locked, 404);
            if (in_array($this->state($locked), ['queued', 'processing', 'ready'], true)) {
                return redirect()->route('exports.show', $locked);
            }
            if (! $this->sourcesExist($locked)) {
                return redirect()->route('exports.show', $locked)->withErrors(['export' => 'A selected photo was removed. Return to the gallery and choose again.']);
            }
            $disk = Storage::disk('local');
            if (! $disk->delete([$locked->archiveFile(), $locked->temporaryFile()])) {
                return redirect()->route('exports.show', $locked)->withErrors(['export' => 'Unable to clear the old ZIP. Please retry.']);
            }
            $locked->update(['status' => 'queued', 'bytes' => null, 'expires_at' => now()->addDay()]);
            BuildPhotoExport::dispatch($locked->id);

            return redirect()->route('exports.show', $locked);
        });
    }

    private function authorizeOwner(Request $request, PhotoExport $export): void
    {
        abort_unless($export->uploadSession->user_id === $request->user()->id, 404);
    }

    private function sourcesExist(PhotoExport $export): bool
    {
        return $export->uploadSession->photos()->whereIn('id', $export->photo_ids)->count() === count($export->photo_ids);
    }

    private function state(PhotoExport $export): string
    {
        if ($export->expires_at->isPast()) {
            return 'expired';
        }
        if (! $this->sourcesExist($export) || ($export->status === 'ready' && ! Storage::disk('local')->exists($export->archiveFile()))) {
            return 'failed';
        }

        return $export->status;
    }
}
