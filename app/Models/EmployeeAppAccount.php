<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's access to one business system (Salesforce, Oracle, …).
 *
 * `requested` means NOC has emailed the app's administrators and put the
 * employee in the security group — the account itself is created by them, so
 * `active` is set by hand once they confirm.
 */
class EmployeeAppAccount extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'employee_id',
        'business_app_id',
        'workflow_id',
        'status',
        'requested_at',
        'activated_at',
        'revoked_at',
        'account_identifier',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(BusinessApp::class, 'business_app_id');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'bg-success',
            self::STATUS_REQUESTED => 'bg-warning text-dark',
            self::STATUS_REVOKED => 'bg-secondary',
            default => 'bg-secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_REQUESTED => 'Requested — awaiting creation',
            self::STATUS_REVOKED => 'Revoked',
            default => ucfirst($this->status),
        };
    }
}
