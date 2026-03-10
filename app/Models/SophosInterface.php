<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SophosInterface extends Model
{
    protected $fillable = [
        'firewall_id',
        'name',
        'hardware',
        'ip_address',
        'netmask',
        'zone',
        'status',
        'mtu',
        'speed',
    ];

    protected $casts = [
        'mtu' => 'integer',
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
