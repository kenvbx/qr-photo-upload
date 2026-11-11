<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\PhotoRemoval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Phar;
use PharData;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PhotoSelectionController extends Controller
{
    public function store(Request $request, UploadSession $collection, PhotoRemoval $removal): BinaryFileResponse|RedirectResponse
    {
        abort_unless($collection->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(['download', 'delete'])],
            'photos' => ['required', 'array', 'min:1', 'max:24'],
            'photos.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $photos = $collection->photos()->whereIn('id', $data['photos'])->orderBy('id')->get();
        abort_unless($photos->count() === count($data['photos']), 404, 'Some photos are no longer available. Refresh the gallery.');

        if ($data['action'] === 'download') {
            return $this->download($photos, $collection);
        }

        $deleted = 0;
        foreach ($photos as $photo) {
            if ($removal->remove($photo, $request->user()->id)) {
                $deleted++;
            }
        }
        $response = redirect()->route('collections.show', $collection)->with('status', $deleted.' '.($deleted === 1 ? 'photo' : 'photos').' deleted.');
        if ($deleted !== $photos->count()) {
            $response->withErrors(['photos' => 'Some photos could not be deleted. Refresh the gallery and retry any remaining photos.']);
        }

        return $response;
    }

    private function download(Collection $photos, UploadSession $collection): BinaryFileResponse|RedirectResponse
    {
        $disk = Storage::disk('local');
        foreach ($photos as $photo) {
            if (! $disk->exists($photo->path)) {
                return redirect()->route('collections.show', $collection)->withErrors(['photos' => 'A selected file is unavailable. Refresh the gallery and choose again.']);
            }
        }

        $path = sys_get_temp_dir().'/gather-'.bin2hex(random_bytes(16)).'.zip';
        $previousMask = umask(0077);
        try {
            $archive = new PharData($path, 0, null, Phar::ZIP);
            foreach ($photos as $photo) {
                $archive->addFile($disk->path($photo->path), 'photo-'.$photo->id.'.'.pathinfo($photo->path, PATHINFO_EXTENSION));
            }
            unset($archive);

            return response()->download($path, 'collection-'.$collection->id.'-selected.zip', [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            report($exception);

            return redirect()->route('collections.show', $collection)->withErrors(['photos' => 'Unable to prepare the selected photos. Refresh the gallery and try again.']);
        } finally {
            umask($previousMask);
        }
    }
}
