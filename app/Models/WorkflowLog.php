<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLog extends Model
{
    // Append-only — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'workflow_id',
        'level',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function levelBadgeClass(): string
    {
        return match ($this->level) {
            'success' => 'bg-success',
            'error'   => 'bg-danger',
            'warning' => 'bg-warning text-dark',
            'info'    => 'bg-info text-dark',
            default   => 'bg-secondary',
        };
    }

    public function levelIcon(): string
    {
        return match ($this->level) {
            'success' => 'bi-check-circle-fill',
            'error'   => 'bi-x-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            'info'    => 'bi-info-circle-fill',
            default   => 'bi-circle',
        };
    }
}
