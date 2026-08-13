<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current state of one ordered leg: caller branch dialling dest branch's IVR.
 */
class VoiceMeshPair extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAIL = 'fail';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = ['caller_node_id', 'dest_node_id'];

    protected $casts = [
        'last_rx_pkt' => 'integer',
        'last_duration_sec' => 'decimal:2',
        'last_reference_sec' => 'decimal:2',
        'consecutive_failures' => 'integer',
        'last_checked_at' => 'datetime',
        'last_ok_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    public function caller(): BelongsTo
    {
        return $this->belongsTo(VoiceMeshNode::class, 'caller_node_id');
    }

    public function dest(): BelongsTo
    {
        return $this->belongsTo(VoiceMeshNode::class, 'dest_node_id');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_OK => 'bg-success',
            self::STATUS_FAIL => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OK => 'OK',
            self::STATUS_FAIL => 'Failed',
            default => 'Unknown',
        };
    }

    public function label(): string
    {
        $caller = $this->caller?->code ?? '?';
        $dest = $this->dest?->code ?? '?';
        $ext = $this->last_dest_ext ?? $this->dest?->ivr_ext;

        return $ext ? "{$caller} → {$dest} ({$ext})" : "{$caller} → {$dest}";
    }

    /**
     * Older than 2.5 sweeps means this leg wasn't in the last run(s) — the
     * matrix greys it rather than showing a stale green.
     */
    public function isStale(?int $intervalMinutes = null): bool
    {
        if (! $this->last_checked_at) {
            return true;
        }

        $interval = $intervalMinutes ?: (int) config('voice_mesh.interval_minutes', 30);

        return $this->last_checked_at->lt(now()->subMinutes((int) round($interval * 2.5)));
    }

    /** How far the recorded prompt drifted from the reference, as a percentage. */
    public function driftPct(): ?float
    {
        $reference = (float) $this->last_reference_sec;
        if ($reference <= 0 || $this->last_duration_sec === null) {
            return null;
        }

        return abs((float) $this->last_duration_sec - $reference) / $reference * 100;
    }
}
