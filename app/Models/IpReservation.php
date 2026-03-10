<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpReservation extends Model
{
    protected $fillable = [
        'branch_id',
        'subnet_id',
        'ip_address',
        'subnet',
        'device_type',
        'device_name',
        'mac_address',
        'vlan',
        'assigned_to',
        'notes',
        'status',
        'source',
        'device_id',
        'last_seen',
    ];

    protected $casts = [
        'vlan'      => 'integer',
        'last_seen' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ipamSubnet(): BelongsTo
    {
        return $this->belongsTo(IpamSubnet::class, 'subnet_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    // ─── Helpers ────────────────────────────────────────────────

    public static function deviceTypes(): array
    {
        return [
            'server'   => 'Server',
            'switch'   => 'Switch',
            'router'   => 'Router',
            'firewall' => 'Firewall',
            'ap'       => 'Access Point',
            'printer'  => 'Printer',
            'phone'    => 'Phone',
            'camera'   => 'Camera',
            'ucm'      => 'UCM / PBX',
            'other'    => 'Other',
        ];
    }

    public function deviceTypeBadgeClass(): string
    {
        return match ($this->device_type) {
            'server'   => 'bg-primary',
            'switch'   => 'bg-info',
            'router'   => 'bg-dark',
            'firewall' => 'bg-danger',
            'ap'       => 'bg-success',
            'printer'  => 'bg-warning text-dark',
            'phone'    => 'bg-secondary',
            default    => 'bg-light text-dark',
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'available' => 'bg-success',
            'reserved'  => 'bg-primary',
            'dhcp'      => 'bg-info',
            'static'    => 'bg-secondary',
            'conflict'  => 'bg-danger',
            'offline'   => 'bg-dark',
            default     => 'bg-light text-dark',
        };
    }
}
