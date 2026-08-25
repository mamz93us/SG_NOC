<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One fetch of a firmware image, parsed out of the nginx access log by
 * `firmware:ingest-log`. See the migration for why this exists at all.
 */
class PhoneFirmwareDownload extends Model
{
    protected $fillable = [
        'ip',
        'mac',
        'model',
        'firmware_version',
        'filename',
        'status',
        'bytes',
        'user_agent',
        'requested_at',
        'line_hash',
    ];

    protected $casts = [
        'status' => 'integer',
        'bytes' => 'integer',
        'requested_at' => 'datetime',
    ];

    // ─── Scopes ───────────────────────────────────────────────────

    /** Actually delivered — 200, or 206 for a phone resuming a partial fetch. */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereIn('status', [200, 206]);
    }

    /** A phone asked for something that is not published. */
    public function scopeMissing(Builder $query): Builder
    {
        return $query->where('status', 404);
    }

    public function scopeFirmwareOnly(Builder $query): Builder
    {
        // Phones pull ringtones (ring1.bin …) from the same path; those are not
        // firmware and would drown the list.
        return $query->where('filename', 'not like', 'ring%');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /** The ITAM asset for this phone, matched on MAC. */
    public function device(): ?Device
    {
        return $this->mac ? Device::where('mac_address', $this->mac)->first() : null;
    }

    public function humanSize(): string
    {
        return DownloadFile::formatBytes($this->bytes);
    }

    /** MAC in the colon form people read it in. */
    public function macFormatted(): ?string
    {
        return $this->mac ? implode(':', str_split(strtoupper($this->mac), 2)) : null;
    }

    public function statusBadgeClass(): string
    {
        return match (true) {
            in_array($this->status, [200, 206], true) => 'bg-success',
            $this->status === 304 => 'bg-secondary',
            $this->status === 404 => 'bg-danger',
            default => 'bg-warning text-dark',
        };
    }
}
