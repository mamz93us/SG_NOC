<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterMaintenanceLog extends Model
{
    protected $fillable = [
        'printer_id',
        'type',
        'description',
        'performed_by_user_id',
        'performed_by_name',
        'cost',
        'performed_at',
        'notes',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'cost'         => 'decimal:2',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'toner_change' => 'bg-primary',
            'repair'       => 'bg-danger',
            'service'      => 'bg-success',
            'inspection'   => 'bg-info text-dark',
            default        => 'bg-secondary',
        };
    }

    public function typeIcon(): string
    {
        return match ($this->type) {
            'toner_change' => 'bi-droplet-fill',
            'repair'       => 'bi-tools',
            'service'      => 'bi-wrench-adjustable',
            'inspection'   => 'bi-search',
            default        => 'bi-clipboard-check',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'toner_change' => 'Toner Change',
            'repair'       => 'Repair',
            'service'      => 'Service',
            'inspection'   => 'Inspection',
            default        => ucfirst($this->type),
        };
    }

    public function performerName(): string
    {
        return $this->performedByUser?->name ?? $this->performed_by_name ?? 'Unknown';
    }
}
