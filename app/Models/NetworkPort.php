<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkPort extends Model
{
    protected $fillable = [
        'switch_serial',
        'port_id',
        'name',
        'enabled',
        'type',
        'vlan',
        'allowed_vlans',
        'poe_enabled',
        'is_uplink',
        'status',
        'speed',
        'duplex',
        'client_mac',
        'client_hostname',
    ];

    protected $casts = [
        'enabled'    => 'boolean',
        'poe_enabled'=> 'boolean',
        'is_uplink'  => 'boolean',
        'vlan'       => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function networkSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_serial', 'serial');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isConnected(): bool
    {
        return strtolower($this->status ?? '') === 'connected';
    }

    public function isDisabled(): bool
    {
        return !$this->enabled || strtolower($this->status ?? '') === 'disabled';
    }

    /**
     * Bootstrap colour class for the port tile.
     *
     * Colour is driven by connection STATUS only — not by isUplink.
     * Meraki marks ANY port connected to network infrastructure (phones,
     * downstream switches, APs) as isUplink=true, so using it for colour
     * would paint most ports blue on aggregation switches. The uplink arrow
     * icon in the tile is sufficient to communicate the uplink flag.
     */
    public function tileBgClass(): string
    {
        if (!$this->enabled) {
            return 'bg-secondary bg-opacity-25';
        }

        return match (strtolower($this->status ?? '')) {
            'connected'    => 'bg-success',
            'disconnected' => 'bg-secondary bg-opacity-50',
            'disabled'     => 'bg-secondary bg-opacity-25',
            default        => 'bg-secondary bg-opacity-50',
        };
    }

    /**
     * Human-readable port label (name or port_id).
     */
    public function label(): string
    {
        return $this->name ?: "Port {$this->port_id}";
    }

    /**
     * Speed display.
     * Meraki port-status API returns a pre-formatted string: "1 Gbps", "100 Mbps", "10 Gbps", etc.
     * Just return it as-is; fall back to numeric conversion only if it looks like a bare number.
     */
    public function speedLabel(): string
    {
        $speed = $this->speed;

        if (!$speed) {
            return '-';
        }

        // Already has a unit label (e.g. "1 Gbps", "100 Mbps") — return as-is
        if (preg_match('/[a-zA-Z]/', $speed)) {
            return $speed;
        }

        // Bare numeric Mbps (fallback for non-standard responses)
        $mbps = (int) $speed;
        if ($mbps >= 1000) {
            return round($mbps / 1000, 1) . ' Gbps';
        }
        return $mbps . ' Mbps';
    }
}
