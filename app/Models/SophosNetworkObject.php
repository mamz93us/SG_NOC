<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SophosNetworkObject extends Model
{
    protected $fillable = [
        'firewall_id',
        'name',
        'object_type',
        'ip_address',
        'subnet',
        'host_type',
        'ipam_synced',
    ];

    protected $casts = [
        'ipam_synced' => 'boolean',
    ];

    public function firewall(): BelongsTo
    {
        return $this->belongsTo(SophosFirewall::class, 'firewall_id');
    }

    public function typeBadgeClass(): string
    {
        return match ($this->object_type) {
            'IP'      => 'bg-primary',
            'Network' => 'bg-info',
            'Range'   => 'bg-warning text-dark',
            default   => 'bg-secondary',
        };
    }
}
