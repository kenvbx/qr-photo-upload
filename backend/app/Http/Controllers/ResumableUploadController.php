<?php

namespace App\Http\Controllers;

use App\Models\ResumableUpload;
use App\Models\UploadSession;
use App\Services\PhotoUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResumableUploadController extends Controller
{
    private function collection(Request $request, string $token): UploadSession
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('upload_sessions')->where('token', $token)->update(['id' => DB::raw('id')]);
        }
        $collection = UploadSession::where('token', $token)->lockForUpdate()->firstOrFail();
        abort_unless(! $collection->password_hash || hash_equals($collection->password_hash, (string) $request->session()->get('upload_grants.'.$collection->id, '')), 403, 'Unlock this upload link before continuing.');

        return $collection;
    }

    private function secret(Request $request): string
    {
        $secret = (string) $request->header('X-Upload-Secret');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $secret), 403);

        return hash('sha256', $secret);
    }

    private function row(UploadSession $collection, string $id, string $secret): ResumableUpload
    {
        $row = ResumableUpload::lockForMutation($id);
        abort_unless($row && $row->upload_session_id === $collection->id && hash_equals($row->secret_hash, $secret), 404);
        abort_if($row->expires_at->isPast(), 410, 'Saved upload expired. Choose the photo again.');
        if (! $row->result) {
            abort_unless($collection->active(), 410, 'This upload link has expired or closed.');
        }

        return $row;
    }

    private function reconcile(ResumableUpload $row): void
    {
        if ($row->result) {
            return;
        }
        $disk = Storage::disk('local');
        $actual = $disk->exists($row->partFile()) ? $disk->size($row->partFile()) : 0;
        $offset = $actual < $row->offset ? 0 : $row->offset;
        if ($actual !== $offset && $disk->exists($row->partFile())) {
            $stream = fopen($disk->path($row->partFile()), 'c+b');
            try {
                if (! ftruncate($stream, $offset)) {
                    throw new \RuntimeException('Unable to reconcile saved upload.');
                }
            } finally {
                fclose($stream);
            }
        }
        if ($offset !== $row->offset) {
            $row->update(['offset' => $offset]);
        }
    }

    private function data(ResumableUpload $row): array
    {
        return ['offset' => (int) $row->offset, 'bytes' => (int) $row->bytes, 'result' => $row->result];
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $secret = $this->secret($request);
        $data = $request->validate(['id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'mime' => ['required', Rule::in(['image/jpeg', 'image/png', 'image/webp'])], 'bytes' => ['required', 'integer', 'min:1', 'max:10485760'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $data['id'] = strtolower($data['id']);

        return DB::transaction(function () use ($request, $token, $data, $secret) {
            $collection = $this->collection($request, $token);
            if (ResumableUpload::whereKey($data['id'])->exists()) {
                $row = $this->row($collection, $data['id'], $secret);
                abort_unless($row->name === $data['name'] && $row->mime === $data['mime'] && (int) $row->bytes === (int) $data['bytes'] && hash_equals($row->sha256, $data['sha256']), 409, 'Upload ID conflicts with another photo.');
                $this->reconcile($row);

                return response()->json(['data' => $this->data($row)]);
            }
            abort_unless($collection->active(), 410);
            abort_if(ResumableUpload::where('upload_session_id', $collection->id)->whereNull('result')->where('expires_at', '>', now())->count() >= 20, 429, 'Too many unfinished photos. Finish or remove saved uploads first.');
            $row = ResumableUpload::create($data + ['upload_session_id' => $collection->id, 'secret_hash' => $secret, 'offset' => 0, 'expires_at' => now()->addDay()]);

            return response()->json(['data' => $this->data($row)], 201);
        });
    }

    public function chunk(Request $request, string $token, string $id): JsonResponse
    {
        $secret = $this->secret($request);
        $data = $request->validate(['offset' => ['required', 'integer', 'min:0', 'max:10485760'], 'chunk' => ['required', 'file', 'max:512']]);

        return DB::transaction(function () use ($request, $token, $id, $secret, $data) {
            $collection = $this->collection($request, $token);
            $row = $this->row($collection, $id, $secret);
            if ($row->result) {
                return response()->json(['data' => $this->data($row)]);
            }
            $this->reconcile($row);
            $offset = (int) $data['offset'];
            $length = $request->file('chunk')->getSize();
            abort_unless($length > 0, 422, 'Chunk must not be empty.');
            abort_if($offset > $row->offset || $offset + $length > $row->bytes, 409, 'Upload position changed. Retry to resume.');
            $disk = Storage::disk('local');
            $disk->makeDirectory('resumable');
            $stream = fopen($disk->path($row->partFile()), 'c+b');
            chmod($disk->path($row->partFile()), 0600);
            try {
                if ($offset < $row->offset) {
                    abort_if($offset + $length > $row->offset, 409, 'Chunk overlaps saved bytes.');
                    fseek($stream, $offset);
                    $saved = fread($stream, $length);
                    abort_unless(hash_equals(hash('sha256', $saved), hash_file('sha256', $request->file('chunk')->getRealPath())), 409, 'Saved chunk differs from retry.');
                } else {
                    fseek($stream, $offset);
                    $input = fopen($request->file('chunk')->getRealPath(), 'rb');
                    try {
                        $copied = stream_copy_to_stream($input, $stream, 524289);
                    } finally {
                        fclose($input);
                    }
                    if ($copied !== $length || ! fflush($stream)) {
                        throw new \RuntimeException('Unable to save upload chunk.');
                    }
                    if (function_exists('fsync') && ! fsync($stream)) {
                        throw new \RuntimeException('Unable to persist upload chunk.');
                    }
                    $row->update(['offset' => $offset + $copied]);
                }
            } finally {
                fclose($stream);
            }

            return response()->json(['data' => $this->data($row)]);
        }, 1);
    }

    public function finish(Request $request, string $token, string $id, PhotoUpload $uploads): JsonResponse
    {
        $secret = $this->secret($request);
        $part = null;
        $response = DB::transaction(function () use ($request, $token, $id, $secret, $uploads, &$part) {
            $collection = $this->collection($request, $token);
            $row = $this->row($collection, $id, $secret);
            if ($row->result) {
                return response()->json(['data' => $row->result]);
            }
            $this->reconcile($row);
            abort_unless((int) $row->offset === (int) $row->bytes, 409, 'Photo is incomplete. Retry to resume.');
            $path = Storage::disk('local')->path($row->partFile());
            abort_unless(is_file($path) && hash_equals($row->sha256, hash_file('sha256', $path)), 422, 'Photo checksum failed. Remove it and choose again.');
            $file = new UploadedFile($path, $row->name, $row->mime, null, true);
            Validator::make(['photo' => $file], ['photo' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240']])->validate();
            $result = $uploads->store($collection, [$file], $id, $request->session()->get('upload_grants.'.$collection->id));
            $row->update(['result' => $result]);
            $part = $row->partFile();

            return response()->json(['data' => $result]);
        }, 1);
        if ($part) {
            Storage::disk('local')->delete($part);
        }

        return $response;
    }

    public function destroy(Request $request, string $token, string $id): JsonResponse
    {
        $secret = $this->secret($request);

        return DB::transaction(function () use ($request, $token, $id, $secret) {
            $collection = $this->collection($request, $token);
            $row = $this->row($collection, $id, $secret);
            abort_unless(Storage::disk('local')->delete($row->partFile()), 503);
            // Keep completed state until expiry: deleting a receipt could resurrect a removed photo.
            if (! $row->result) {
                $row->delete();
            }

            return response()->json(['data' => ['discarded' => true]]);
        });
    }
}
