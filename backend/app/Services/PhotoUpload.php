<?php

namespace App\Services;

use App\Models\UploadSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhotoUpload
{
    public function store(UploadSession $session, array $files): int
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($session, $files, &$paths) {
                $locked = UploadSession::query()->lockForUpdate()->findOrFail($session->id);
                abort_unless($locked->active(), 410, 'This upload link has expired or closed.');
                if ($locked->photos()->count() + count($files) > 100) {
                    throw ValidationException::withMessages(['photos' => 'This collection can hold up to 100 photos.']);
                }
                foreach ($files as $index => $file) {
                    $dimensions = @getimagesize($file->getRealPath());
                    $mime = $file->getMimeType();
                    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                    if (! $dimensions || ! $extension || ($dimensions['mime'] ?? '') !== $mime || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 20000000) {
                        throw ValidationException::withMessages(['photos.'.$index => 'Use valid JPEG, PNG or WebP images, up to 20 megapixels and 12,000 pixels per side.']);
                    }
                    if (! function_exists('imagecreatefromstring')) {
                        throw new \RuntimeException('PHP GD is required for image validation.');
                    }
                    $decoded = @imagecreatefromstring(file_get_contents($file->getRealPath()));
                    if ($decoded === false) {
                        throw ValidationException::withMessages(['photos.'.$index => 'The image could not be decoded. Please choose a different file.']);
                    }
                    imagedestroy($decoded);
                    $path = 'photos/'.$locked->id.'/'.Str::uuid().'.'.$extension;
                    // Track before writing so even a partial write is cleaned up.
                    $paths[] = $path;
                    if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
                        throw new \RuntimeException('Photo storage failed.');
                    }
                    $locked->photos()->create(['path' => $path, 'mime' => $mime, 'bytes' => $file->getSize(), 'width' => $dimensions[0], 'height' => $dimensions[1]]);
                }
                DB::table('audit_events')->insert(['action' => 'photos.uploaded', 'resource_id' => $locked->id, 'created_at' => now()]);

                return count($files);
            }, 1);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }
    }
}
