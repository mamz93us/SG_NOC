<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device's last reported wallpaper state. Written by the public check-in
 * endpoint the PowerShell agent calls after applying. One row per hostname.
 */
class WallpaperCheckin extends Model
{
    protected $fillable = [
        'hostname',
        'domain_detected',
        'wallpaper_set_id',
        'set_label',
        'desktop_hash',
        'lockscreen_hash',
        'os_version',
        'ip_address',
        'checkin_count',
        'last_applied_at',
    ];

    protected $casts = [
        'last_applied_at' => 'datetime',
    ];

    public function wallpaperSet(): BelongsTo
    {
        return $this->belongsTo(WallpaperSet::class);
    }
}
