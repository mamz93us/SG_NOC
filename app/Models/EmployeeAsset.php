<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAsset extends Model
{
    protected $fillable = [
        'employee_id',
        'asset_id',
        'assigned_date',
        'returned_date',
        'condition',
        'notes',
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'returned_date' => 'date',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'asset_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->returned_date === null;
    }

    public function conditionBadgeClass(): string
    {
        return match ($this->condition) {
            'good' => 'bg-success',
            'fair' => 'bg-warning text-dark',
            'poor' => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
