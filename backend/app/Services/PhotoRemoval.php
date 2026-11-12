<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PhotoRemoval
{
    public function trash(Photo $photo, int $userId): bool
    {
        return DB::transaction(function () use ($photo, $userId) {
            $locked = Photo::lockForMutation($photo->id);
            if (! $locked) {
                return false;
            }
            $locked->delete();
            DB::table('audit_events')->insert(['user_id' => $userId, 'action' => 'photo.trashed', 'resource_id' => $locked->id, 'created_at' => now()]);

            return true;
        });
    }

    public function remove(Photo $photo, int $userId, bool $onlyTrashed = false, bool $onlyDue = false): bool
    {
        return DB::transaction(function () use ($photo, $userId, $onlyTrashed, $onlyDue) {
            $locked = Photo::lockForMutation($photo->id, true);
            if (! $locked) {
                return false;
            }
            if (($onlyTrashed && ! $locked->trashed()) || ($onlyDue && ! ($locked->delete_after?->lte(now()) || $locked->deleted_at?->lte(now()->subDays(30))))) {
                return false;
            }
            $disk = Storage::disk($locked->disk);
            foreach ([$locked->thumbnailFile(), $locked->thumbnailFile().'.tmp'] as $derivative) {
                if ($disk->exists($derivative) && ! $disk->delete($derivative)) {
                    return false;
                }
            }
            if (! $disk->delete($locked->path)) {
                return false;
            }
            DB::table('audit_events')->insert(['user_id' => $userId, 'action' => 'photo.deleted', 'resource_id' => $locked->id, 'created_at' => now()]);
            $locked->forceDelete();

            return true;
        });
    }
}
