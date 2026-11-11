<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\PhotoExportBuilder;
use App\Services\PhotoRemoval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhotoSelectionController extends Controller
{
    public function store(Request $request, UploadSession $collection, PhotoRemoval $removal, PhotoExportBuilder $exports): RedirectResponse
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
            $export = $exports->request($collection, $data['photos']);

            return redirect()->route('exports.show', $export);
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
}
