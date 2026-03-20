<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = [
        'name', 'description', 'severity', 'target_type',
        'sensor_class', 'operator', 'threshold_value',
        'delay_seconds', 'interval_seconds', 'recovery_alert', 'disabled',
        'notify_email', 'notify_emails', 'notify_slack', 'slack_webhook',
    ];

    protected $casts = [
        'threshold_value'  => 'float',
        'delay_seconds'    => 'integer',
        'interval_seconds' => 'integer',
        'recovery_alert'   => 'boolean',
        'disabled'         => 'boolean',
        'notify_email'     => 'boolean',
        'notify_slack'     => 'boolean',
    ];

    public function states()
    {
        return $this->hasMany(AlertState::class);
    }

    public function activeStates()
    {
        return $this->hasMany(AlertState::class)->where('state', 'alerted');
    }

    public function evaluate(float $value): bool
    {
        return match ($this->operator) {
            '<='    => $value <= $this->threshold_value,
            '>='    => $value >= $this->threshold_value,
            '<'     => $value < $this->threshold_value,
            '>'     => $value > $this->threshold_value,
            '=='    => $value == $this->threshold_value,
            '!='    => $value != $this->threshold_value,
            default => false,
        };
    }

    public function severityBadge(): string
    {
        return match ($this->severity) {
            'critical' => 'danger',
            'warning'  => 'warning',
            default    => 'success',
        };
    }
}
