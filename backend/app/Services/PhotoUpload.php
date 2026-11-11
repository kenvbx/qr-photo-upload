<?php

namespace App\Services;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhotoUpload
{
    /** @param array<int, UploadedFile> $files
     * @return array{uploaded: int, duplicates: int, replayed: bool}
     */
    public function store(UploadSession $session, array $files, ?string $uploadKey = null, ?string $passwordGrant = null): array
    {
        $entries = [];
        foreach ($files as $index => $file) {
            $entries[] = $this->inspect($file, $index);
        }
        $fingerprint = hash('sha256', json_encode(array_column($entries, 'sha256'), JSON_THROW_ON_ERROR));
        $paths = [];
        $disk = config('photos.disk');
        abort_unless(in_array($disk, ['local', 's3', 'r2'], true), 503);
        try {
            return DB::transaction(function () use ($session, $entries, $uploadKey, $fingerprint, $disk, $passwordGrant, &$paths) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('upload_sessions')->where('id', $session->id)->update(['id' => DB::raw('id')]);
                }
                $locked = UploadSession::query()->lockForUpdate()->findOrFail($session->id);
                abort_unless(! $locked->password_hash || hash_equals($locked->password_hash, $passwordGrant ?? ''), 403, 'Unlock this upload link before sending photos.');
                if ($uploadKey !== null) {
                    $receipt = DB::table('upload_receipts')->where('upload_session_id', $locked->id)->where('upload_key', $uploadKey)->first();
                    if ($receipt) {
                        abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'This upload ID was already used for different photos. Choose the files again.');

                        return ['uploaded' => (int) $receipt->uploaded, 'duplicates' => (int) $receipt->duplicates, 'replayed' => true];
                    }
                }
                abort_unless($locked->active(), 410, 'This upload link has expired or closed.');
                $this->hashLegacyPhotos($locked);
                $existing = $locked->photos()->whereIn('sha256', array_column($entries, 'sha256'))->pluck('sha256')->flip()->all();
                $new = [];
                foreach ($entries as $entry) {
                    if (! array_key_exists($entry['sha256'], $existing)) {
                        $new[] = $entry;
                        $existing[$entry['sha256']] = true;
                    }
                }
                if ($locked->photos()->count() + count($new) > 100) {
                    throw ValidationException::withMessages(['photos' => 'This collection can hold up to 100 photos.']);
                }
                if ($locked->photos()->sum('bytes') + array_sum(array_map(fn ($entry) => $entry['file']->getSize(), $new)) > $locked->quota_bytes) {
                    throw ValidationException::withMessages(['photos' => 'This collection has reached its storage quota.']);
                }
                foreach ($new as $entry) {
                    $path = 'photos/'.$locked->id.'/'.Str::uuid().'.'.$entry['extension'];
                    $paths[] = $path;
                    if (! Storage::disk($disk)->putFileAs(dirname($path), $entry['file'], basename($path))) {
                        throw new \RuntimeException('Photo storage failed.');
                    }
                    $photo = $locked->photos()->create(['disk' => $disk, 'original_name' => mb_substr(basename(str_replace('\\', '/', $entry['file']->getClientOriginalName())), 0, 255), 'delete_after' => $locked->retention_days ? now()->addDays($locked->retention_days) : null, 'path' => $path, 'mime' => $entry['mime'], 'bytes' => $entry['file']->getSize(), 'width' => $entry['width'], 'height' => $entry['height'], 'sha256' => $entry['sha256']]);
                    GeneratePhotoThumbnail::dispatch($photo->id);
                }
                $result = ['uploaded' => count($new), 'duplicates' => count($entries) - count($new), 'replayed' => false];
                if ($uploadKey !== null) {
                    DB::table('upload_receipts')->insert(['upload_session_id' => $locked->id, 'upload_key' => $uploadKey, 'fingerprint' => $fingerprint, 'uploaded' => $result['uploaded'], 'duplicates' => $result['duplicates'], 'created_at' => now()]);
                }
                if ($result['uploaded'] > 0) {
                    DB::table('audit_events')->insert(['action' => 'photos.uploaded', 'resource_id' => $locked->id, 'created_at' => now()]);
                }

                return $result;
            }, 1);
        } catch (\Throwable $error) {
            if ($paths !== []) {
                try {
                    Storage::disk($disk)->delete($paths);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }
    }

    /** @return array{file: UploadedFile, sha256: string, mime: string, extension: string, width: int, height: int} */
    private function inspect(UploadedFile $file, int $index): array
    {
        $dimensions = @getimagesize($file->getRealPath());
        $mime = $file->getMimeType();
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $dimensions || ! $extension || ($dimensions['mime'] ?? '') !== $mime || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 20000000) {
            throw ValidationException::withMessages(['photos.'.$index => 'Use valid JPEG, PNG or WebP images, up to 20 megapixels and 12,000 pixels per side.']);
        }
        $decoded = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if ($decoded === false) {
            throw ValidationException::withMessages(['photos.'.$index => 'The image could not be decoded. Please choose a different file.']);
        }
        imagedestroy($decoded);
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            throw new \RuntimeException('Unable to read photo.');
        }

        return ['file' => $file, 'sha256' => $hash, 'mime' => $mime, 'extension' => $extension, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    private function hashLegacyPhotos(UploadSession $session): void
    {
        foreach ($session->photos()->whereNull('sha256')->get() as $photo) {
            $stream = Storage::disk($photo->disk)->readStream($photo->path);
            if (! is_resource($stream)) {
                continue;
            }
            try {
                $context = hash_init('sha256');
                if (hash_update_stream($context, $stream) === false) {
                    throw new \RuntimeException('Unable to read existing photo.');
                }
                $hash = hash_final($context);
            } finally {
                fclose($stream);
            }
            if (! $session->photos()->where('sha256', $hash)->exists()) {
                $photo->update(['sha256' => $hash]);
            }
        }
    }
}
