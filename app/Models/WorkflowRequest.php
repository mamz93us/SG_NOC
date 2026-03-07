<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRequest extends Model
{
    protected $fillable = [
        'type',
        'title',
        'description',
        'payload',
        'branch_id',
        'requested_by',
        'status',
        'current_step',
        'total_steps',
    ];

    protected $casts = [
        'payload'      => 'array',
        'current_step' => 'integer',
        'total_steps'  => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'workflow_id')->orderBy('step_number');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'workflow_id')->orderBy('created_at');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'draft'     => 'bg-secondary',
            'pending'   => 'bg-warning text-dark',
            'approved'  => 'bg-info text-dark',
            'rejected'  => 'bg-danger',
            'executing' => 'bg-primary',
            'completed' => 'bg-success',
            'failed'    => 'bg-danger',
            default     => 'bg-secondary',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'create_user', 'extension_create', 'asset_assign' => 'bg-success',
            'delete_user', 'extension_delete', 'asset_return' => 'bg-danger',
            'license_change', 'license_purchase'              => 'bg-info text-dark',
            default                                           => 'bg-secondary',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'create_user'       => 'Create User',
            'delete_user'       => 'Delete User',
            'license_change'    => 'License Change',
            'license_purchase'  => 'License Purchase',
            'asset_assign'      => 'Asset Assignment',
            'asset_return'      => 'Asset Return',
            'extension_create'  => 'Create Extension',
            'extension_delete'  => 'Delete Extension',
            default             => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    public function currentStepRecord(): ?WorkflowStep
    {
        return $this->steps()->where('step_number', $this->current_step)->first();
    }

    public function isAwaitingMyApproval(int $userId): bool
    {
        if (!in_array($this->status, ['pending'])) {
            return false;
        }

        $step = $this->currentStepRecord();
        if (!$step || $step->status !== 'pending') {
            return false;
        }

        // If step is assigned to specific user
        if ($step->approver_id && $step->approver_id === $userId) {
            return true;
        }

        // If step is role-based, check user's role
        if (!$step->approver_id) {
            $user = User::find($userId);
            if (!$user) return false;

            return match ($step->approver_role) {
                'super_admin' => $user->role === 'super_admin',
                'it_manager'  => in_array($user->role, ['super_admin', 'admin']),
                'hr'          => in_array($user->role, ['super_admin', 'admin']),
                'manager'     => in_array($user->role, ['super_admin', 'admin']),
                'security'    => in_array($user->role, ['super_admin', 'admin']),
                default       => false,
            };
        }

        return false;
    }

    public function progressPercent(): int
    {
        if ($this->total_steps === 0) return 0;
        return (int) round(($this->current_step / $this->total_steps) * 100);
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'executing']);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
