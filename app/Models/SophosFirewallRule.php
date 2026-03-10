<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SophosFirewallRule extends Model
{
    protected $fillable = [
        'firewall_id',
        'rule_name',
        'position',
        'source_zone',
        'dest_zone',
        'source_networks',
        'dest_networks',
        'services',
        'action',
        'enabled',
        'log_traffic',
    ];

    protected $casts = [
        'position'        => 'integer',
        'source_networks' => 'array',
        'dest_networks'   => 'array',
        'services'        => 'array',
        'enabled'         => 'boolean',
        'log_traffic'     => 'boolean',
    ];

    public function firewall(): BelongsTo
    {
        return $this->belongsTo(SophosFirewall::class, 'firewall_id');
    }

    public function actionBadgeClass(): string
    {
        return match ($this->action) {
            'accept' => 'bg-success',
            'drop'   => 'bg-danger',
            'reject' => 'bg-warning text-dark',
            default  => 'bg-secondary',
        };
    }
}
