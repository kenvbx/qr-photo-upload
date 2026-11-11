<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectionController extends Controller
{
    public function index(Request $r)
    {
        $collections = UploadSession::where('user_id', $r->user()->id)->withCount('photos')->latest()->paginate(12);

        return view('dashboard', compact('collections'));
    }

    public function store(Request $r)
    {
        $data = $r->validate(['title' => ['required', 'string', 'max:120'], 'hours' => ['required', 'integer', 'min:1', 'max:168']]);
        $collection = DB::transaction(function () use ($r, $data) {
            $c = UploadSession::create(['user_id' => $r->user()->id, 'title' => $data['title'], 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addHours((int) $data['hours'])]);
            DB::table('audit_events')->insert(['user_id' => $r->user()->id, 'action' => 'collection.created', 'resource_id' => $c->id, 'created_at' => now()]);

            return $c;
        });

        return redirect()->route('collections.show', $collection)->with('status', 'Collection created. Share the QR code to start collecting.');
    }

    public function show(Request $r, UploadSession $collection)
    {
        abort_unless($collection->user_id === $r->user()->id, 404);
        $photos = $collection->photos()->latest()->latest('id')->paginate(24);
        $url = rtrim(config('app.url'), '/').'/u/'.$collection->token;
        $qr = (new Writer(new ImageRenderer(new RendererStyle(260, 4), new SvgImageBackEnd)))->writeString($url);

        return view('collection', compact('collection', 'photos', 'url', 'qr'));
    }

    public function close(Request $r, UploadSession $collection)
    {
        abort_unless($collection->user_id === $r->user()->id, 404);
        DB::transaction(function () use ($r, $collection) {
            $locked = UploadSession::lockForUpdate()->findOrFail($collection->id);
            if (! $locked->closed_at) {
                $locked->update(['closed_at' => now()]);
                DB::table('audit_events')->insert(['user_id' => $r->user()->id, 'action' => 'collection.closed', 'resource_id' => $locked->id, 'created_at' => now()]);
            }
        });

        return redirect()->route('collections.show', $collection)->with('status', 'Upload link closed. Your photos are still available.');
    }
}
