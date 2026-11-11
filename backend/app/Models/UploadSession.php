<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadSession extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'password_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function active(): bool
    {
        return $this->closed_at === null && $this->expires_at->isFuture();
    }
}
