<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SophosCentralAccessPoint extends Model
{
    protected $fillable = [
        'central_id',
        'name',
        'serial_number',
        'mac_address',
        'model',
        'firmware_version',
        'status',
        'site_id',
        'site_name',
        'ip_address',
        'central_last_seen_at',
        'raw',
    ];

    protected $casts = [
        'raw' => 'array',
        'central_last_seen_at' => 'datetime',
    ];

    public function isOnline(): bool
    {
        return strtolower((string) $this->status) === 'online';
    }

    public function isOffline(): bool
    {
        return strtolower((string) $this->status) === 'offline';
    }

    public function statusBadgeClass(): string
    {
        return match (strtolower((string) $this->status)) {
            'online' => 'bg-success',
            'offline' => 'bg-danger',
            'pending', 'unknown' => 'bg-secondary',
            default => 'bg-warning text-dark',
        };
    }
}
