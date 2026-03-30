<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSshSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'user_id', 'status', 'ssh_username',
        'client_ip', 'started_at', 'ended_at', 'duration_seconds', 'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Close the session and record duration. */
    public function close(string $status = 'closed'): void
    {
        $ended = now();
        $this->update([
            'status'           => $status,
            'ended_at'         => $ended,
            'duration_seconds' => (int) $this->started_at->diffInSeconds($ended),
        ]);
    }

    /** Human-readable duration string. */
    public function durationLabel(): string
    {
        $s = $this->duration_seconds;
        if ($s === null) return '—';
        if ($s < 60)   return "{$s}s";
        if ($s < 3600) return round($s / 60) . 'm';
        return round($s / 3600, 1) . 'h';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active'       => 'bg-success',
            'closed'       => 'bg-secondary',
            'disconnected' => 'bg-warning text-dark',
            default        => 'bg-secondary',
        };
    }
}
