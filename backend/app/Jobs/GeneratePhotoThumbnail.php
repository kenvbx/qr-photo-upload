<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\PhotoThumbnail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePhotoThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $photoId)
    {
        $this->onConnection('database')->onQueue('thumbnails')->beforeCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PhotoThumbnail $thumbnails): void
    {
        $thumbnails->generate($this->photoId);
    }

    public function failed(?Throwable $exception): void
    {
        Photo::whereKey($this->photoId)->where('thumbnail_status', '!=', 'ready')->update(['thumbnail_status' => 'failed']);
    }
}
