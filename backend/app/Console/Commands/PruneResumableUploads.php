<?php

namespace App\Console\Commands;

use App\Models\ResumableUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneResumableUploads extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Remove expired private resumable upload staging files';

    public function handle(): int
    {
        $failed = 0;
        ResumableUpload::where('expires_at', '<=', now())->chunkById(100, function ($rows) use (&$failed) {
            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($row) {
                        $locked = ResumableUpload::lockForMutation($row->id);
                        if (! $locked || $locked->expires_at->isFuture()) {
                            return;
                        }
                        if (! Storage::disk('local')->delete($locked->partFile())) {
                            throw new \RuntimeException('Unable to prune saved upload.');
                        }
                        $locked->delete();
                    });
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });
        $this->info('Saved upload cleanup finished; '.$failed.' failures.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
