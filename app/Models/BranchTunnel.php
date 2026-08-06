<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A branch VPN tunnel as the watchdog sees it: a gateway (the branch firewall
 * we ping) plus a set of probes for the subnets the tunnel is supposed to
 * carry. Self-contained — not linked to the retired strongSwan VpnTunnel rows;
 * tunnels live on the Azure VPN gateway now.
 *
 * `state` is the roll-up written by tunnel-health:watch:
 *   up       — gateway and every active probe answering
 *   degraded — gateway answering but a probe is down (traffic selector missing
 *              a subnet, or a dead host on the far side)
 *   down     — gateway not answering; the tunnel itself is not passing traffic
 */
class BranchTunnel extends Model
{
    public const STATE_UP = 'up';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_DOWN = 'down';

    public const STATE_UNKNOWN = 'unknown';

    protected $fillable = [
        'name',
        'branch_id',
        'firewall_ip',
        'is_active',
        'state',
        'state_changed_at',
        'ping_status',
        'ping_latency_ms',
        'last_ping_at',
        'consecutive_failures',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ping_latency_ms' => 'integer',
        'last_ping_at' => 'datetime',
        'state_changed_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'sort_order' => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function probes(): HasMany
    {
        return $this->hasMany(TunnelProbe::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeProbes(): HasMany
    {
        return $this->probes()->where('is_active', true);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(TunnelHealthCheck::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function isUp(): bool
    {
        return $this->state === self::STATE_UP;
    }

    /** Degraded and down both mean "someone needs to look at this". */
    public function needsAttention(): bool
    {
        return in_array($this->state, [self::STATE_DEGRADED, self::STATE_DOWN], true);
    }

    public function stateBadgeClass(): string
    {
        return match ($this->state) {
            self::STATE_UP => 'bg-success',
            self::STATE_DEGRADED => 'bg-warning text-dark',
            self::STATE_DOWN => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_UP => 'Up',
            self::STATE_DEGRADED => 'Degraded',
            self::STATE_DOWN => 'Down',
            default => 'Unknown',
        };
    }

    /**
     * Percentage of watchdog cycles in the window where the roll-up was "up".
     * Returns null when there is no history yet (fresh install, new tunnel).
     */
    public function uptimePercent(int $hours = 24): ?float
    {
        $total = $this->checks()->where('checked_at', '>=', now()->subHours($hours))->count();
        if ($total === 0) {
            return null;
        }

        $up = $this->checks()
            ->where('checked_at', '>=', now()->subHours($hours))
            ->where('state', self::STATE_UP)
            ->count();

        return round($up / $total * 100, 2);
    }
}
