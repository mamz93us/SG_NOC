<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterSupply extends Model
{
    protected $fillable = [
        'printer_id', 'supply_oid', 'supply_capacity_oid', 'supply_index',
        'supply_type', 'supply_color', 'supply_descr', 'supply_capacity',
        'supply_current', 'supply_percent', 'part_number',
        'warning_threshold', 'critical_threshold',
        'consumption_rate', 'estimated_days_remaining', 'last_updated_at',
    ];

    protected $casts = [
        'supply_capacity' => 'integer',
        'supply_current' => 'integer',
        'supply_percent' => 'integer',
        'warning_threshold' => 'integer',
        'critical_threshold' => 'integer',
        'consumption_rate' => 'float',
        'estimated_days_remaining' => 'integer',
        'last_updated_at' => 'datetime',
    ];

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function isLow(): bool
    {
        return $this->supply_percent !== null && $this->supply_percent <= $this->warning_threshold;
    }

    public function isCritical(): bool
    {
        return $this->supply_percent !== null && $this->supply_percent <= $this->critical_threshold;
    }

    public function colorClass(): string
    {
        if ($this->isCritical()) return 'danger';
        if ($this->isLow()) return 'warning';
        return 'success';
    }

    public function colorDot(): string
    {
        return match($this->supply_color) {
            'cyan' => '#17a2b8',
            'magenta' => '#e83e8c',
            'yellow' => '#ffc107',
            'waste' => '#6c757d',
            default => '#343a40', // black
        };
    }

    public function typeIcon(): string
    {
        return match($this->supply_type) {
            'drum' => 'bi-circle',
            'fuser' => 'bi-thermometer-half',
            'waste' => 'bi-trash',
            'maintenance' => 'bi-tools',
            default => 'bi-droplet-fill',
        };
    }

    public function updateConsumption(): void
    {
        if ($this->supply_percent === null) return;

        // Calculate consumption rate using exponential moving average
        if ($this->last_updated_at && $this->consumption_rate !== null) {
            $daysSince = $this->last_updated_at->diffInHours(now()) / 24;
            if ($daysSince > 0 && $daysSince < 1) {
                // Not enough time elapsed, skip
                return;
            }
        }

        // Estimate days remaining
        if ($this->consumption_rate > 0) {
            $this->estimated_days_remaining = (int) ($this->supply_percent / $this->consumption_rate);
        }

        $this->last_updated_at = now();
    }
}
