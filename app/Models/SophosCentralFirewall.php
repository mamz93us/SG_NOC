<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SophosCentralFirewall extends Model
{
    protected $fillable = [
        'central_id',
        'name',
        'hostname',
        'serial_number',
        'model',
        'firmware_version',
        'status',
        'group_name',
        'cluster_mode',
        'available_firmware',
        'raw',
        'sophos_firewall_id',
    ];

    protected $casts = [
        'raw' => 'array',
        'available_firmware' => 'array',
    ];

    public function localFirewall(): BelongsTo
    {
        return $this->belongsTo(SophosFirewall::class, 'sophos_firewall_id');
    }

    public function isConnected(): bool
    {
        return strtolower((string) $this->status) === 'connected';
    }

    public function hasFirmwareUpgrade(): bool
    {
        return ! empty($this->available_firmware);
    }

    public function statusBadgeClass(): string
    {
        return match (strtolower((string) $this->status)) {
            'connected' => 'bg-success',
            'disconnected' => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
