<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DhcpLease extends Model
{
    protected $fillable = [
        'branch_id',
        'subnet_id',
        'ip_address',
        'mac_address',
        'hostname',
        'vendor',
        'vlan',
        'source',
        'source_device',
        'network_label',
        'interface',
        'is_reserved',
        'lease_start',
        'lease_end',
        'last_seen',
        'device_id',
        'switch_serial',
        'port_id',
        'is_conflict',
    ];

    protected $casts = [
        'vlan' => 'integer',
        'is_conflict' => 'boolean',
        'is_reserved' => 'boolean',
        'lease_start' => 'datetime',
        'lease_end' => 'datetime',
        'last_seen' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subnet(): BelongsTo
    {
        return $this->belongsTo(IpamSubnet::class, 'subnet_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function networkSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_serial', 'serial');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('last_seen', '>=', now()->subHours(24));
    }

    public function scopeConflicts(Builder $query): Builder
    {
        return $query->where('is_conflict', true);
    }

    public function scopeSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function sourceBadgeClass(): string
    {
        return match ($this->source) {
            'meraki' => 'bg-primary',
            'sophos' => 'bg-danger',
            'fortigate' => 'bg-dark',
            'snmp' => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'fortigate' => 'FortiGate',
            'snmp' => 'SNMP',
            default => ucfirst((string) $this->source),
        };
    }

    /**
     * The firewall that handed out this lease, if it is one we manage.
     */
    public function fortigate(): BelongsTo
    {
        return $this->belongsTo(FortigateFirewall::class, 'source_device', 'ip');
    }

    public function displayName(): string
    {
        return $this->hostname ?: $this->mac_address;
    }

    /**
     * Where the client physically attached: switch + port for Meraki, or the
     * firewall + interface for a FortiGate/ARP-derived lease.
     */
    public function connectionPoint(): string
    {
        if ($this->switch_serial) {
            $switch = $this->networkSwitch?->name ?: $this->switch_serial;

            return $this->port_id ? "{$switch} · port {$this->port_id}" : $switch;
        }

        if ($this->interface) {
            return trim(($this->source_device ?: '').' · '.$this->interface, ' ·');
        }

        return $this->source_device ?: '—';
    }

    /**
     * 169.254.0.0/16 — the address a host picks for itself when DHCP fails.
     * It says the NIC is up but got no lease; it locates nothing.
     */
    public function isSelfAssigned(): bool
    {
        return str_starts_with((string) $this->ip_address, '169.254.');
    }

    /**
     * Leases stop refreshing when the device leaves, so anything older than a
     * day is history rather than "currently connected".
     */
    public function isCurrent(): bool
    {
        return $this->last_seen !== null && $this->last_seen->gt(now()->subDay());
    }
}
