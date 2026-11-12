<?php

namespace App\Http\Controllers;

use App\Models\PhotoExport;
use App\Models\UploadSession;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $filters = $r->validate(['q' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', Rule::in(['jpeg', 'png', 'webp'])], 'moderation' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'largest', 'smallest'])]]);
        $query = $collection->photos();
        if ($state = $filters['moderation'] ?? null) {
            $query->where('moderation_status', $state);
        }
        if (($search = $filters['q'] ?? '') !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereRaw("original_name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('mime', 'image/'.$type);
        }
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'largest' => $query->orderByDesc('bytes')->orderByDesc('id'),
            'smallest' => $query->orderBy('bytes')->orderBy('id'),
            default => $query->latest()->latest('id'),
        };
        $photos = $query->paginate(24)->withQueryString();
        $usage = (int) $collection->photos()->withTrashed()->sum('bytes');
        if ($r->expectsJson()) {
            $exports = PhotoExport::where('upload_session_id', $collection->id)->latest()->limit(5)->get();
            $html = view('collection-gallery', compact('collection', 'photos', 'exports'))->render();

            return response()->json(['html' => $html, 'revision' => hash('sha256', $html), 'usage' => number_format($usage / 1048576, 1).' MB', 'photo_count' => $collection->photos()->count()])->header('Cache-Control', 'private, no-store');
        }
        $url = rtrim(config('app.url'), '/').'/u/'.$collection->token;
        $qr = (new Writer(new ImageRenderer(new RendererStyle(260, 4), new SvgImageBackEnd)))->writeString($url);

        $exports = PhotoExport::where('upload_session_id', $collection->id)->latest()->limit(5)->get();

        return view('collection', compact('collection', 'photos', 'url', 'qr', 'exports', 'usage'));
    }

    public function update(Request $r, UploadSession $collection): RedirectResponse
    {
        abort_unless($collection->user_id === $r->user()->id, 404);
        $data = $r->validate([
            'quota_mb' => ['required', 'integer', 'min:1', 'max:1000'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'remove_password' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($collection, $data, $r) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('upload_sessions')->where('id', $collection->id)->update(['id' => DB::raw('id')]);
            }
            $locked = UploadSession::lockForUpdate()->findOrFail($collection->id);
            if ($locked->photos()->withTrashed()->sum('bytes') > $data['quota_mb'] * 1048576) {
                throw ValidationException::withMessages(['quota_mb' => 'Quota cannot be lower than current usage.']);
            }
            $values = ['quota_bytes' => $data['quota_mb'] * 1048576, 'retention_days' => $data['retention_days'] ?? null];
            if ($r->boolean('remove_password')) {
                $values['password_hash'] = null;
            } elseif (! empty($data['password'])) {
                $values['password_hash'] = Hash::make($data['password']);
            }
            $locked->update($values);
            DB::table('audit_events')->insert(['user_id' => $r->user()->id, 'action' => 'collection.settings_updated', 'resource_id' => $locked->id, 'created_at' => now()]);
        });

        return redirect()->route('collections.show', $collection)->with('status', 'Settings saved. Retention applies to future uploads only; existing deadlines are unchanged.');
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
