<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadSession extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'share_token', 'password_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'closed_at' => 'datetime', 'share_expires_at' => 'datetime', 'share_enabled' => 'boolean'];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function shared(): bool
    {
        return $this->share_enabled && $this->share_token && $this->share_expires_at?->isFuture();
    }

    public function brandForeground(): string
    {
        $rgb = array_map(fn ($hex) => hexdec($hex) / 255, str_split(substr($this->brand_color, 1), 2));
        $linear = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);
        $luminance = $linear[0] * 0.2126 + $linear[1] * 0.7152 + $linear[2] * 0.0722;

        return $luminance > 0.179 ? '#000000' : '#ffffff';
    }

    public function active(): bool
    {
        return $this->closed_at === null && $this->expires_at->isFuture();
    }
}
