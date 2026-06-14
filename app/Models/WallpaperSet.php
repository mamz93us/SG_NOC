<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One wallpaper set per AD domain (desktop + lock screen). Images live on the
 * `public` disk so they resolve to a stable, unauthenticated URL the per-device
 * PowerShell script can download. See the create migration for the full rationale.
 */
class WallpaperSet extends Model
{
    use HasFactory;

    /** Public disk — files served straight by the web server at /storage/… (no auth). */
    public const DISK = 'public';

    protected $fillable = [
        'label',
        'domain_match',
        'is_default',
        'enabled',
        'desktop_path',
        'desktop_hash',
        'lockscreen_path',
        'lockscreen_hash',
        'updated_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function desktopUrl(): ?string
    {
        return $this->desktop_path ? Storage::disk(self::DISK)->url($this->desktop_path) : null;
    }

    public function lockscreenUrl(): ?string
    {
        return $this->lockscreen_path ? Storage::disk(self::DISK)->url($this->lockscreen_path) : null;
    }

    public function hasImages(): bool
    {
        return (bool) ($this->desktop_path || $this->lockscreen_path);
    }
}
