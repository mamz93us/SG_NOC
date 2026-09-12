<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrImportRow extends Model
{
    protected $fillable = [
        'hr_import_batch_id',
        'row_number',
        'emp_no',
        'emp_name',
        'email',
        'own_mailbox',
        'mailbox_reason',
        'mobile_raw',
        'mobile_normalized',
        'location_name',
        'dept_no',
        'dept_name',
        'job_name',
        'gender',
        'matched_employee_id',
        'match_method',
        'resolved_branch_id',
        'status',
        'decision',
        'linked_employee_id',
        'error_note',
    ];

    /** Why the address in the EMAIL column is not this person's own mailbox. */
    public const MAILBOX_REASONS = [
        'blank' => 'No email address',
        'outside_domain' => 'Email is not on a company domain',
        'shared' => 'Email is shared with other rows (a manager\'s)',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'resolved_branch_id' => 'integer',
        'own_mailbox' => 'boolean',
    ];

    public function mailboxReasonLabel(): ?string
    {
        return $this->own_mailbox ? null : (self::MAILBOX_REASONS[$this->mailbox_reason] ?? 'No mailbox of their own');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HrImportBatch::class, 'hr_import_batch_id');
    }

    public function matchedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_employee_id');
    }

    public function linkedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'linked_employee_id');
    }

    public function resolvedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'resolved_branch_id');
    }

    /**
     * The employee this row is (or would be) acting on — the matched one, or the
     * one chosen during unmatched resolution.
     */
    public function effectiveEmployee(): ?Employee
    {
        return $this->matchedEmployee ?? $this->linkedEmployee;
    }
}
