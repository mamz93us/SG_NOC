<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HR correction to one person's day: corrected check-in / check-out
 * times, or the whole day excused. The raw punches are never touched — the
 * day is rebuilt with this laid over them.
 *
 * One active correction per person per day. A new one revokes the previous
 * one rather than editing it, so the history of who changed what stays.
 */
class AttendanceAdjustment extends Model
{
    use StoresPlainDates;

    public const EXCUSES = [
        'annual_leave' => 'Annual leave',
        'sick_leave' => 'Sick leave',
        'mission' => 'Business mission',
        'wfh' => 'Work from home',
        'permission' => 'Permission',
        'other' => 'Other',
    ];

    protected array $plainDates = ['work_date'];

    protected $fillable = [
        'employee_id',
        'work_date',
        'check_in',
        'check_out',
        'excuse',
        'reason',
        'created_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'work_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function summary(): string
    {
        if ($this->excuse) {
            return 'Excused: '.(self::EXCUSES[$this->excuse] ?? $this->excuse);
        }

        $parts = [];
        if ($this->check_in) {
            $parts[] = 'check-in '.$this->check_in->format('H:i');
        }
        if ($this->check_out) {
            $parts[] = 'check-out '.$this->check_out->format($this->check_out->isSameDay($this->work_date) ? 'H:i' : 'd M H:i');
        }

        return 'Set '.implode(', ', $parts);
    }
}
