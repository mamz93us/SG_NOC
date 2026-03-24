<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryResult extends Model
{
    protected $fillable = [
        'discovery_scan_id', 'ip_address', 'hostname', 'mac_address',
        'vendor', 'model', 'sys_name', 'sys_descr', 'device_type',
        'is_reachable', 'snmp_accessible', 'already_imported',
        'imported_type', 'imported_id', 'raw_data',
    ];

    protected $casts = [
        'is_reachable'    => 'boolean',
        'snmp_accessible' => 'boolean',
        'already_imported'=> 'boolean',
        'raw_data'        => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DiscoveryScan::class, 'discovery_scan_id');
    }

    public function deviceTypeBadgeClass(): string
    {
        return match ($this->device_type) {
            'printer' => 'primary',
            'switch'  => 'info',
            'device'  => 'secondary',
            default   => 'light text-muted border',
        };
    }

    public function deviceTypeIcon(): string
    {
        return match ($this->device_type) {
            'printer' => 'bi-printer',
            'switch'  => 'bi-diagram-3',
            'device'  => 'bi-cpu',
            default   => 'bi-question-circle',
        };
    }
}
