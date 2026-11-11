<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\Photo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QueuePhotoThumbnails extends Command
{
    protected $signature = 'photos:thumbnails {--collection= : Limit to one collection ID} {--retry-failed : Requeue exhausted jobs too}';

    protected $description = 'Queue missing thumbnails for existing photos without modifying originals';

    public function handle(): int
    {
        $collectionId = $this->option('collection');
        if ($collectionId !== null && (! ctype_digit($collectionId) || (int) $collectionId < 1)) {
            $this->error('Collection must be a positive integer.');

            return self::FAILURE;
        }
        $queued = 0;
        Photo::query()->when($collectionId !== null, fn ($query) => $query->where('upload_session_id', $collectionId))
            ->chunkById(100, function ($photos) use (&$queued) {
                foreach ($photos as $photo) {
                    DB::transaction(function () use ($photo, &$queued) {
                        $locked = Photo::lockForMutation($photo->id);
                        if (! $locked || ($locked->thumbnail_status === 'failed' && ! $this->option('retry-failed'))
                            || ($locked->thumbnail_status === 'ready' && Storage::disk('local')->exists($locked->thumbnailFile()))) {
                            return;
                        }
                        $locked->update(['thumbnail_status' => 'pending']);
                        GeneratePhotoThumbnail::dispatch($locked->id);
                        $queued++;
                    });
                }
            });
        $this->info($queued.' thumbnail jobs queued.');

        return self::SUCCESS;
    }
}
