<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SophosVpnTunnel extends Model
{
    protected $fillable = [
        'firewall_id',
        'name',
        'connection_type',
        'policy',
        'remote_gateway',
        'local_subnet',
        'remote_subnet',
        'status',
        'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
    ];

    public function firewall(): BelongsTo
    {
        return $this->belongsTo(SophosFirewall::class, 'firewall_id');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'up'   => 'bg-success',
            'down' => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
