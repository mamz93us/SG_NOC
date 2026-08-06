<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reachability check carried out through a branch tunnel — either an ICMP
 * ping or a TCP connect to a specific port.
 *
 * TCP probes matter for services whose host answers ICMP but whose port is
 * blocked or dead; ICMP probes matter for subnets where nothing listens on a
 * predictable port. Between them they tell you whether the tunnel's traffic
 * selector actually covers the subnet.
 */
class TunnelProbe extends Model
{
    public const TYPE_ICMP = 'icmp';

    public const TYPE_TCP = 'tcp';

    protected $fillable = [
        'branch_tunnel_id',
        'label',
        'target',
        'check_type',
        'port',
        'is_active',
        'status',
        'latency_ms',
        'last_checked_at',
        'status_changed_at',
        'consecutive_failures',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'port' => 'integer',
        'latency_ms' => 'integer',
        'last_checked_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'sort_order' => 'integer',
    ];

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(BranchTunnel::class, 'branch_tunnel_id');
    }

    public function isTcp(): bool
    {
        return $this->check_type === self::TYPE_TCP;
    }

    /** "10.1.8.10:8089" for TCP probes, plain IP for ICMP. */
    public function targetLabel(): string
    {
        return $this->isTcp() && $this->port ? "{$this->target}:{$this->port}" : $this->target;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'up' => 'bg-success',
            'down' => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
