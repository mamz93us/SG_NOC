<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A branch firewall we ping to gauge whether its Azure VPN tunnel is up.
 * Self-contained — not linked to the (now removed) strongSwan VpnTunnel rows.
 */
class BranchTunnel extends Model
{
    protected $fillable = [
        'name',
        'firewall_ip',
        'is_active',
        'ping_status',
        'ping_latency_ms',
        'last_ping_at',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ping_latency_ms' => 'integer',
        'last_ping_at' => 'datetime',
        'sort_order' => 'integer',
    ];
}
