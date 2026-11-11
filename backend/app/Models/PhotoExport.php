<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PhotoExport extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['photo_ids' => 'array', 'expires_at' => 'datetime'];
    }

    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }

    public function archiveFile(): string
    {
        return 'exports/'.$this->id.'.zip';
    }

    public function temporaryFile(): string
    {
        return 'exports/'.$this->id.'.building.zip';
    }

    /** Call inside a transaction; reserve the SQLite writer before reading. */
    public static function lockForMutation(string $id): ?self
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('photo_exports')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return static::lockForUpdate()->find($id);
    }
}
