<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Photo extends Model
{
    protected $guarded = ['id'];

    public function thumbnailFile(): string
    {
        return 'thumbnails/'.$this->upload_session_id.'/'.$this->id.'.jpg';
    }

    /** Reserve the writer before reading on SQLite; call inside a transaction. */
    public static function lockForMutation(int $id): ?self
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('photos')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return static::lockForUpdate()->find($id);
    }

    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }
}
