<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PhotoRemoval
{
    public function remove(Photo $photo, int $userId): bool
    {
        return DB::transaction(function () use ($photo, $userId) {
            $locked = Photo::lockForMutation($photo->id);
            if (! $locked) {
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
            $locked->delete();

            return true;
        });
    }
}
