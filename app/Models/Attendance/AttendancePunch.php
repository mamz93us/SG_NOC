<?php

namespace App\Models\Attendance;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw BioTime punch, copied verbatim. Never edited — see attendance_days.
 *
 * punch_time is the device's wall clock; it is read and shown as-is and must
 * never be passed through setTimezone().
 */
class AttendancePunch extends Model
{
    public $timestamps = false;

    /** BioTime's punch_state codes. Stored for reference; they do not decide in/out. */
    public const STATES = [
        '0' => 'Check in',
        '1' => 'Check out',
        '2' => 'Break out',
        '3' => 'Break in',
        '4' => 'Overtime in',
        '5' => 'Overtime out',
    ];

    protected $fillable = [
        'biotime_source_id',
        'biotime_id',
        'biotime_employee_id',
        'employee_id',
        'emp_code',
        'punch_time',
        'punch_state',
        'terminal_sn',
        'terminal_alias',
        'area_alias',
        'synced_at',
    ];

    protected $casts = [
        'biotime_id' => 'integer',
        'employee_id' => 'integer',
        'punch_time' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiotimeSource::class, 'biotime_source_id');
    }

    public function biotimeEmployee(): BelongsTo
    {
        return $this->belongsTo(BiotimeEmployee::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function stateLabel(): string
    {
        return self::STATES[(string) $this->punch_state] ?? '—';
    }
}
