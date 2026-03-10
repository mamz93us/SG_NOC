<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
        'lease_start',
        'lease_end',
        'last_seen',
        'device_id',
        'switch_serial',
        'port_id',
        'is_conflict',
    ];

    protected $casts = [
        'vlan'        => 'integer',
        'is_conflict' => 'boolean',
        'lease_start' => 'datetime',
        'lease_end'   => 'datetime',
        'last_seen'   => 'datetime',
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
            'snmp'   => 'bg-warning text-dark',
            default  => 'bg-secondary',
        };
    }

    public function displayName(): string
    {
        return $this->hostname ?: $this->mac_address;
    }
}
