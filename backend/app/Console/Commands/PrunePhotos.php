<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\PhotoRemoval;
use Illuminate\Console\Command;

class PrunePhotos extends Command
{
    protected $signature = 'photos:prune';

    protected $description = 'Remove photos whose explicitly configured retention deadline has passed';

    public function handle(PhotoRemoval $removal): int
    {
        $failed = 0;
        $removed = 0;
        Photo::whereNotNull('delete_after')->where('delete_after', '<=', now())->with('uploadSession')->chunkById(100, function ($photos) use ($removal, &$failed, &$removed) {
            foreach ($photos as $photo) {
                try {
                    if ($removal->remove($photo, $photo->uploadSession->user_id)) {
                        $removed++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });
        $this->info($removed.' expired photos removed; '.$failed.' failed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
