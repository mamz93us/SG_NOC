<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Denormalised state row for a single offboarding (1-to-1 with workflow_requests).
 * Owns the manager's decisions and the lifecycle dates.
 */
class OffboardingWorkflow extends Model
{
    protected $fillable = [
        'workflow_id',
        'employee_id',
        'status',
        'email_action',
        'forward_emails',
        'forward_until',
        'laptop_action',
        'asset_action',
        'asset_target_employee_id',
        'retrieval_choices',
        'expected_last_day',
        'azure_disabled_at',
        'azure_deleted_at',
        'delete_after',
        'manager_grace_until',
        'escalated_at',
        'completed_at',
        'forward_rule_id',
    ];

    protected $casts = [
        'forward_emails'      => 'array',
        'retrieval_choices'   => 'array',
        'expected_last_day'   => 'date',
        'forward_until'       => 'date',
        'azure_disabled_at'   => 'datetime',
        'azure_deleted_at'    => 'datetime',
        'delete_after'        => 'date',
        'manager_grace_until' => 'date',
        'escalated_at'        => 'datetime',
        'completed_at'        => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assetTarget(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'asset_target_employee_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(OffboardingBackup::class);
    }

    public function token()
    {
        // Tokens link back via workflow_id (the WorkflowRequest id).
        return $this->hasOne(OffboardingToken::class, 'workflow_id', 'workflow_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * True when every requested backup has finished uploading.
     * Used by the scheduler before final-deleting the Azure user.
     */
    public function allBackupsComplete(): bool
    {
        $required = $this->backups()->whereNotIn('status', ['pruned'])->get();
        if ($required->isEmpty()) {
            return true;
        }
        return $required->every(fn($b) => in_array($b->status, ['completed', 'pruned'], true));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'manager_input_pending' => 'bg-warning text-dark',
            'processing'            => 'bg-info text-dark',
            'active'                => 'bg-primary',
            'escalated'             => 'bg-danger',
            'retention'             => 'bg-secondary',
            'completed'             => 'bg-success',
            'failed'                => 'bg-danger',
            'cancelled'             => 'bg-secondary',
            default                 => 'bg-secondary',
        };
    }
}
