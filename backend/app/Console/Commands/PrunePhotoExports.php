<?php

namespace App\Console\Commands;

use App\Models\PhotoExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrunePhotoExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Remove expired private ZIP files and their export records';

    public function handle(): int
    {
        $removed = 0;
        PhotoExport::where('expires_at', '<=', now())->chunkById(100, function ($exports) use (&$removed) {
            foreach ($exports as $export) {
                DB::transaction(function () use ($export, &$removed) {
                    $locked = PhotoExport::lockForMutation($export->id);
                    if (! $locked || $locked->expires_at->isFuture()) {
                        return;
                    }
                    if (! Storage::disk('local')->delete([$locked->archiveFile(), $locked->temporaryFile()])) {
                        throw new RuntimeException('Unable to remove expired ZIP files.');
                    }
                    if (! Storage::disk('local')->deleteDirectory('exports/'.$locked->id.'.sources')) {
                        throw new RuntimeException('Unable to remove expired ZIP staging files.');
                    }
                    $locked->delete();
                    $removed++;
                });
            }
        });
        $this->info($removed.' expired exports removed.');

        return self::SUCCESS;
    }
}
