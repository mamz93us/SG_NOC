<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTask extends Model
{
    protected $fillable = [
        'workflow_id',
        'type',
        'title',
        'description',
        'status',
        'payload',
        'assigned_to',
        'due_date',
        'completed_at',
        'completed_by',
        'notes',
    ];

    protected $casts = [
        'payload'      => 'array',
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'pending'     => 'bg-warning text-dark',
            'in_progress' => 'bg-primary',
            'completed'   => 'bg-success',
            'cancelled'   => 'bg-secondary',
            default       => 'bg-secondary',
        };
    }

    public function typeIcon(): string
    {
        return match ($this->type) {
            'laptop_assign'   => 'bi-laptop',
            'ip_phone_assign' => 'bi-telephone',
            default           => 'bi-check2-square',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'laptop_assign'   => 'Laptop Assignment',
            'ip_phone_assign' => 'IP Phone Setup',
            default           => 'Task',
        };
    }
}
