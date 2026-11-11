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
            $locked = Photo::lockForUpdate()->find($photo->id);
            if (! $locked || ! Storage::disk('local')->delete($locked->path)) {
                return false;
            }
            DB::table('audit_events')->insert(['user_id' => $userId, 'action' => 'photo.deleted', 'resource_id' => $locked->id, 'created_at' => now()]);
            $locked->delete();

            return true;
        });
    }
}
