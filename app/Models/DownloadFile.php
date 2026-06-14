<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file in the Download Center. The bytes live on the `azure_downloads` disk
 * (downloads/ prefix); this row is the catalogue entry. `source` records how it
 * got there — a direct upload (synchronous, lands as `stored`) or a URL the
 * downloads:fetch-remote command streams in (`pending` → `fetching` → `stored`/
 * `failed`). A file is private by default; an admin can opt in to a tokenised
 * public link with an optional expiry, and revoke/rotate it anytime.
 */
class DownloadFile extends Model
{
    use HasFactory;

    public const DISK = 'azure_downloads';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_STORED = 'stored';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_URL = 'url';

    protected $fillable = [
        'title',
        'original_filename',
        'disk',
        'azure_path',
        'size',
        'mime',
        'sha256',
        'source',
        'source_url',
        'status',
        'error',
        'download_total_bytes',
        'download_received_bytes',
        'public_enabled',
        'public_token',
        'public_expires_at',
        'download_count',
        'last_downloaded_at',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'download_total_bytes' => 'integer',
        'download_received_bytes' => 'integer',
        'public_enabled' => 'boolean',
        'public_expires_at' => 'datetime',
        'download_count' => 'integer',
        'last_downloaded_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeStored(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_STORED);
    }

    // ─── State helpers ────────────────────────────────────────────

    public function isStored(): bool
    {
        return $this->status === self::STATUS_STORED;
    }

    public function isPublicExpired(): bool
    {
        return $this->public_expires_at !== null && $this->public_expires_at->isPast();
    }

    /** Enabled, has a token, and not past its expiry. */
    public function isPublicAvailable(): bool
    {
        return $this->public_enabled
            && $this->public_token
            && $this->isStored()
            && ! $this->isPublicExpired();
    }

    /**
     * UI label for the share state: Disabled | Active | Expired.
     */
    public function publicState(): string
    {
        if (! $this->public_enabled || ! $this->public_token) {
            return 'Disabled';
        }

        return $this->isPublicExpired() ? 'Expired' : 'Active';
    }

    public function publicShareUrl(): ?string
    {
        return $this->public_token ? route('downloads.share', $this->public_token) : null;
    }

    public function suggestedDownloadName(): string
    {
        return $this->original_filename ?: ('download-'.$this->id);
    }

    public function humanSize(): string
    {
        return self::formatBytes($this->size);
    }

    public static function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
