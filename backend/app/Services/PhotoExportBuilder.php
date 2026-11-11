<?php

namespace App\Services;

use App\Jobs\BuildPhotoExport;
use App\Models\Photo;
use App\Models\PhotoExport;
use App\Models\UploadSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Phar;
use PharData;
use RuntimeException;
use Throwable;

class PhotoExportBuilder
{
    /** @param array<int, int|string> $ids */
    public function request(UploadSession $collection, array $ids): PhotoExport
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return DB::transaction(function () use ($collection, $ids) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('upload_sessions')->where('id', $collection->id)->update(['id' => DB::raw('id')]);
            }
            UploadSession::lockForUpdate()->findOrFail($collection->id);
            $photos = $collection->photos()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($photos->count() === count($ids), 404, 'Some selected photos are no longer available.');
            foreach ($photos as $photo) {
                if (! Storage::disk($photo->disk)->exists($photo->path)) {
                    throw ValidationException::withMessages(['photos' => 'A selected file is unavailable. Refresh the gallery and choose again.']);
                }
            }
            $fingerprint = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
            $existing = PhotoExport::where('upload_session_id', $collection->id)->where('fingerprint', $fingerprint)
                ->where('expires_at', '>', now())->whereIn('status', ['queued', 'processing', 'ready'])->latest()->first();
            if ($existing && ($existing->status !== 'ready' || Storage::disk('local')->exists($existing->archiveFile()))) {
                return $existing;
            }
            $export = PhotoExport::create(['upload_session_id' => $collection->id, 'photo_ids' => $ids, 'fingerprint' => $fingerprint, 'status' => 'queued', 'expires_at' => now()->addDay()]);
            BuildPhotoExport::dispatch($export->id);

            return $export;
        });
    }

    public function build(string $id): void
    {
        DB::transaction(function () use ($id) {
            $export = PhotoExport::lockForMutation($id);
            if (! $export || $export->expires_at->isPast()) {
                return;
            }
            if ($export->status !== 'ready') {
                $export->update(['status' => 'processing']);
            }
        });
        DB::transaction(function () use ($id) {
            $export = PhotoExport::lockForMutation($id);
            if (! $export || $export->expires_at->isPast()) {
                return;
            }
            $disk = Storage::disk('local');
            if ($export->status === 'ready' && $disk->exists($export->archiveFile())) {
                return;
            }
            $photos = Photo::where('upload_session_id', $export->upload_session_id)->whereIn('id', $export->photo_ids)->orderBy('id')->lockForUpdate()->get();
            if ($photos->count() !== count($export->photo_ids)) {
                $disk->delete($export->temporaryFile());
                throw new RuntimeException('A selected photo was removed.');
            }
            $disk->makeDirectory('exports');
            chmod($disk->path('exports'), 0700);
            $previousMask = umask(0077);
            $published = false;
            $sources = [];
            $sourceDirectory = 'exports/'.$export->id.'.sources';
            try {
                $disk->delete($export->temporaryFile());
                $disk->deleteDirectory($sourceDirectory);
                $archive = new PharData($disk->path($export->temporaryFile()), 0, null, Phar::ZIP);
                foreach ($photos as $photo) {
                    $sourceDisk = Storage::disk($photo->disk);
                    if (! $sourceDisk->exists($photo->path) || $sourceDisk->size($photo->path) > 10 * 1024 * 1024) {
                        throw new RuntimeException('A selected source is unavailable.');
                    }
                    if ($photo->disk === 'local') {
                        $sourcePath = $disk->path($photo->path);
                    } else {
                        $disk->makeDirectory($sourceDirectory);
                        $sourcePath = $disk->path($sourceDirectory.'/'.$photo->id);
                        touch($sourcePath);
                        chmod($sourcePath, 0600);
                        $sources[] = $sourcePath;
                        $stream = $sourceDisk->readStream($photo->path);
                        if (! is_resource($stream)) {
                            throw new RuntimeException('Unable to read source.');
                        }
                        $output = fopen($sourcePath, 'wb');
                        try {
                            $copied = stream_copy_to_stream($stream, $output, 10 * 1024 * 1024 + 1);
                            if ($copied !== (int) $photo->bytes || $copied > 10 * 1024 * 1024) {
                                throw new RuntimeException('Source size changed.');
                            }
                        } finally {
                            fclose($stream);
                            fclose($output);
                        }
                    }
                    $archive->addFile($sourcePath, 'photo-'.$photo->id.'.'.pathinfo($photo->path, PATHINFO_EXTENSION));
                }
                unset($archive);
                if (! $disk->move($export->temporaryFile(), $export->archiveFile())) {
                    throw new RuntimeException('ZIP publishing failed.');
                }
                $published = true;
                $export->update(['status' => 'ready', 'bytes' => $disk->size($export->archiveFile())]);
            } catch (Throwable $exception) {
                $disk->delete($export->temporaryFile());
                if ($published) {
                    $disk->delete($export->archiveFile());
                }
                throw $exception;
            } finally {
                foreach ($sources as $source) {
                    @unlink($source);
                }
                $disk->deleteDirectory($sourceDirectory);
                umask($previousMask);
            }
        }, 1);
    }
}
