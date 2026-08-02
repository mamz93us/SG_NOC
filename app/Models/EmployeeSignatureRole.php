<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional email-signature role for an employee (a second job title +
 * department under the same mailbox). Rendered as an extra, user-selectable
 * classic-Outlook signature. See [[Employee::signatureRoles]].
 */
class EmployeeSignatureRole extends Model
{
    protected $fillable = [
        'employee_id',
        'label',
        'job_title',
        'department',
        'sort_order',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
