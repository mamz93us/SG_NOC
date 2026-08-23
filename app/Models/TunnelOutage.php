<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A continuous stretch a tunnel spent down or degraded.
 *
 * Opened by TunnelWatchdog the first cycle a tunnel leaves `up`, closed the
 * cycle it comes back (or the cycle it changes to the other bad state — down
 * and degraded are separate incidents, because "the link is dead" and "the link
 * is up but not carrying the voice VLAN" are different tickets to different
 * people).
 *
 * Kept forever. tunnel_health_checks is pruned at 7 days; this is the long
 * record used for ISP SLA claims.
 */
class TunnelOutage extends Model
{
    /** Gateway not answering — the link itself. This is the ISP's problem. */
    public const STATE_DOWN = 'down';

    /** Gateway answering, a carried subnet unreachable — usually a traffic selector. */
    public const STATE_DEGRADED = 'degraded';

    /** Below this fraction of expected checks, the incident spans a monitoring gap. */
    public const COVERAGE_THRESHOLD = 0.8;

    protected $fillable = [
        'branch_tunnel_id',
        'state',
        'started_at',
        'ended_at',
        'duration_seconds',
        'checks',
        'probes_down',
        'reason',
        'source',
        'ticket_ref',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'checks' => 'integer',
        'probes_down' => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(BranchTunnel::class, 'branch_tunnel_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Incidents that touch the window at all — one that started before it and is
     * still running is very much part of the period being reported on.
     */
    public function scopeOverlapping(Builder $query, $from, $to): Builder
    {
        return $query->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function isOngoing(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Seconds elapsed, counting an open incident up to now.
     *
     * duration_seconds is kept roughly current on open rows so SQL can sum it,
     * but it is only exact once the incident closes — an open one is always
     * measured against the clock instead.
     */
    public function seconds(): int
    {
        if ($this->ended_at === null) {
            return max(0, $this->started_at->diffInSeconds(now()));
        }

        return $this->duration_seconds ?? max(0, $this->started_at->diffInSeconds($this->ended_at));
    }

    /** Seconds of this incident that fall inside an arbitrary window. */
    public function secondsWithin($from, $to): int
    {
        $start = $this->started_at->greaterThan($from) ? $this->started_at : $from;
        $end = $this->ended_at ?? now();
        $end = $end->lessThan($to) ? $end : $to;

        return $end->greaterThan($start) ? $start->diffInSeconds($end) : 0;
    }

    /**
     * Fraction of the incident the watchdog actually observed: cycles recorded
     * against minutes elapsed. Well under 1.0 means the watchdog was not running
     * for part of it, and the duration is an upper bound, not a measurement.
     */
    public function coverage(): float
    {
        $minutes = max(1, (int) ceil($this->seconds() / 60));

        return min(1.0, $this->checks / $minutes);
    }

    public function hasMonitoringGap(): bool
    {
        // A one-cycle blip is 1 check over <1 minute — always "covered".
        return $this->seconds() >= 180 && $this->coverage() < self::COVERAGE_THRESHOLD;
    }

    public function stateLabel(): string
    {
        return $this->state === self::STATE_DOWN ? 'Down' : 'Degraded';
    }

    public function stateBadgeClass(): string
    {
        return $this->state === self::STATE_DOWN ? 'bg-danger' : 'bg-warning text-dark';
    }

    /** "2h 14m", "45m", "38s" — what a ticket wants to read. */
    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $days = intdiv($hours, 24);

        if ($days > 0) {
            $h = $hours % 24;

            return $h > 0 ? "{$days}d {$h}h" : "{$days}d";
        }

        if ($hours > 0) {
            $m = $minutes % 60;

            return $m > 0 ? "{$hours}h {$m}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
