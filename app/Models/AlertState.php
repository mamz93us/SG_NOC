<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertState extends Model
{
    protected $fillable = [
        'alert_rule_id', 'entity_type', 'entity_id', 'state',
        'triggered_value', 'first_triggered_at', 'last_alerted_at',
        'acknowledged_at', 'acknowledged_by', 'recovered_at', 'alert_count',
    ];

    protected $casts = [
        'triggered_value'    => 'float',
        'first_triggered_at' => 'datetime',
        'last_alerted_at'    => 'datetime',
        'acknowledged_at'    => 'datetime',
        'recovered_at'       => 'datetime',
        'alert_count'        => 'integer',
    ];

    public function rule()
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function isActive(): bool
    {
        return $this->state === 'alerted';
    }

    public function isAcknowledged(): bool
    {
        return $this->state === 'acknowledged';
    }

    public function stateBadge(): string
    {
        return match ($this->state) {
            'alerted'      => 'danger',
            'acknowledged' => 'warning',
            default        => 'success',
        };
    }
}
