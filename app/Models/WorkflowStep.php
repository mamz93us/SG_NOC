<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_id',
        'step_number',
        'approver_role',
        'approver_id',
        'status',
        'acted_by',
        'acted_at',
        'comments',
        'step_type',
        'step_config',
        'node_id',
    ];

    protected $casts = [
        'acted_at'    => 'datetime',
        'step_number' => 'integer',
        'step_config' => 'array',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'approved' => 'bg-success',
            'rejected' => 'bg-danger',
            'skipped'  => 'bg-secondary',
            'pending'  => 'bg-warning text-dark',
            default    => 'bg-secondary',
        };
    }

    public function statusIcon(): string
    {
        return match ($this->status) {
            'approved' => 'bi-check-circle-fill text-success',
            'rejected' => 'bi-x-circle-fill text-danger',
            'skipped'  => 'bi-skip-forward-fill text-secondary',
            'pending'  => 'bi-clock-fill text-warning',
            default    => 'bi-circle text-secondary',
        };
    }

    public function approverRoleLabel(): string
    {
        return match ($this->approver_role) {
            'manager'     => 'Manager',
            'it_manager'  => 'IT Manager',
            'hr'          => 'HR',
            'security'    => 'Security',
            'super_admin' => 'Super Admin',
            default       => ucfirst($this->approver_role),
        };
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
