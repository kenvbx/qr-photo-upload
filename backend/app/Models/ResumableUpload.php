<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ResumableUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['result' => 'array', 'expires_at' => 'datetime'];
    }

    public function partFile(): string
    {
        return 'resumable/'.$this->id.'.part';
    }

    public static function lockForMutation(string $id): ?self
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('resumable_uploads')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return static::lockForUpdate()->find($id);
    }
}
