<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reachability check carried out through a branch tunnel — an ICMP ping, a
 * TCP connect, a SIP OPTIONS ping, or a bare UDP datagram to a media port.
 *
 * TCP probes matter for services whose host answers ICMP but whose port is
 * blocked or dead; ICMP probes matter for subnets where nothing listens on a
 * predictable port. Between them they tell you whether the tunnel's traffic
 * selector actually covers the subnet.
 *
 * The UDP pair exists because voice is the reason these tunnels exist and voice
 * is carried on UDP only: a tunnel policy can permit ICMP and TCP/8089 while
 * dropping 5060 and the RTP range, so every board goes green while calls fail or
 * come up one-way. SIP is checked with a real OPTIONS ping (the UCM answers);
 * RTP has no listener when no call is up, so that probe passes when *anything*
 * comes back — a datagram or the host's ICMP port-unreachable — since either
 * proves the datagram crossed the tunnel and reached the far side.
 */
class TunnelProbe extends Model
{
    public const TYPE_ICMP = 'icmp';

    public const TYPE_TCP = 'tcp';

    /** SIP OPTIONS ping over UDP — up only when a SIP/2.0 response comes back. */
    public const TYPE_SIP = 'sip';

    /** Bare UDP datagram, for RTP/media ports. Up on a reply OR an ICMP port-unreachable. */
    public const TYPE_UDP = 'udp';

    /** Standard SIP signalling port — offered as the default for a SIP probe. */
    public const DEFAULT_SIP_PORT = 5060;

    protected $fillable = [
        'branch_tunnel_id',
        'label',
        'target',
        'check_type',
        'port',
        'is_active',
        'status',
        'latency_ms',
        'result_note',
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

    /** SIP and RTP probes share one transport and one batch. */
    public function isUdp(): bool
    {
        return in_array($this->check_type, [self::TYPE_SIP, self::TYPE_UDP], true);
    }

    /** Every check type except ICMP is aimed at a port. */
    public function usesPort(): bool
    {
        return $this->check_type !== self::TYPE_ICMP;
    }

    /** "10.1.8.10:8089", "10.1.8.10:5060/udp", or a plain IP for ICMP. */
    public function targetLabel(): string
    {
        if (! $this->usesPort() || ! $this->port) {
            return $this->target;
        }

        return "{$this->target}:{$this->port}".($this->isUdp() ? '/udp' : '');
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
