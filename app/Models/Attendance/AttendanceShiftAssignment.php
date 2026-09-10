<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift applied to everyone, a branch, a department or one employee, from a
 * date. ShiftResolver picks the most specific one that covers a day.
 */
class AttendanceShiftAssignment extends Model
{
    use StoresPlainDates;

    public const SCOPES = [
        'all' => 'Everyone',
        'branch' => 'Branch',
        'department' => 'Department',
        'employee' => 'Employee',
    ];

    protected array $plainDates = ['effective_from', 'effective_to'];

    protected $fillable = [
        'attendance_shift_id',
        'scope_type',
        'scope_id',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'attendance_shift_id' => 'integer',
        'scope_id' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShift::class, 'attendance_shift_id');
    }
}
