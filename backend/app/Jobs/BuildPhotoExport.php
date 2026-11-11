<?php

namespace App\Jobs;

use App\Models\PhotoExport;
use App\Services\PhotoExportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildPhotoExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $exportId)
    {
        $this->onConnection('database')->onQueue('exports')->beforeCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PhotoExportBuilder $builder): void
    {
        try {
            $builder->build($this->exportId);
        } catch (Throwable $exception) {
            PhotoExport::whereKey($this->exportId)->where('status', '!=', 'ready')->update(['status' => 'queued']);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        PhotoExport::whereKey($this->exportId)->where('status', '!=', 'ready')->update(['status' => 'failed']);
    }
}
