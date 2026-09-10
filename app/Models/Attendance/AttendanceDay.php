<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's attendance for one work day. Derived — rebuilt by
 * AttendanceDayProcessor from attendance_punches, shifts, holidays and HR
 * corrections whenever any of those change.
 *
 * status: present | absent | excused.
 */
class AttendanceDay extends Model
{
    use StoresPlainDates;

    public const STATUS_LABELS = [
        AttendanceDayBuilder::STATUS_PRESENT => 'Present',
        AttendanceDayBuilder::STATUS_ABSENT => 'Absent',
        AttendanceDayBuilder::STATUS_EXCUSED => 'Excused',
    ];

    protected array $plainDates = ['work_date'];

    protected $fillable = [
        'subject_key',
        'work_date',
        'status',
        'employee_id',
        'biotime_employee_id',
        'branch_id',
        'attendance_shift_id',
        'emp_codes',
        'scheduled_start',
        'scheduled_end',
        'window_start',
        'window_end',
        'first_in',
        'last_out',
        'punch_count',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'overtime_minutes',
        'excuse',
        'attendance_adjustment_id',
        'flags',
        'has_error',
        'computed_at',
    ];

    protected $casts = [
        'work_date' => 'date',
        'employee_id' => 'integer',
        'biotime_employee_id' => 'integer',
        'branch_id' => 'integer',
        'attendance_shift_id' => 'integer',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'first_in' => 'datetime',
        'last_out' => 'datetime',
        'punch_count' => 'integer',
        'worked_minutes' => 'integer',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'attendance_adjustment_id' => 'integer',
        'flags' => 'array',
        'has_error' => 'boolean',
        'computed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function biotimeEmployee(): BelongsTo
    {
        return $this->belongsTo(BiotimeEmployee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShift::class, 'attendance_shift_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(AttendanceAdjustment::class, 'attendance_adjustment_id');
    }

    /** The raw punches this day was built from — its window, which runs past midnight for an overnight shift. */
    public function punchesQuery(): Builder
    {
        $date = $this->work_date->toDateString();
        $start = $this->window_start?->format('Y-m-d H:i:s') ?? $date.' 00:00:00';
        $end = $this->window_end?->format('Y-m-d H:i:s') ?? CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00';

        $query = AttendancePunch::query()->where('punch_time', '>=', $start)->where('punch_time', '<', $end);

        return $this->employee_id
            ? $query->where('employee_id', $this->employee_id)
            : $query->where('biotime_employee_id', $this->biotime_employee_id)->whereNull('employee_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function workedLabel(): string
    {
        return $this->worked_minutes === null ? '—' : self::hoursLabel($this->worked_minutes);
    }

    /** @return list<array{label: string, error: bool}> */
    public function flagBadges(): array
    {
        return array_map(function (string $flag) {
            $label = AttendanceDayBuilder::LABELS[$flag] ?? $flag;

            $minutes = match ($flag) {
                AttendanceDayBuilder::FLAG_LATE => $this->late_minutes,
                AttendanceDayBuilder::FLAG_EARLY_LEAVE => $this->early_leave_minutes,
                AttendanceDayBuilder::FLAG_OVERTIME => $this->overtime_minutes,
                default => null,
            };
            if ($minutes) {
                $label .= ' '.self::minutesLabel($minutes);
            }
            if ($flag === AttendanceDayBuilder::FLAG_EXCUSED && $this->excuse) {
                $label .= ': '.(AttendanceAdjustment::EXCUSES[$this->excuse] ?? $this->excuse);
            }

            return ['label' => $label, 'error' => in_array($flag, AttendanceDayBuilder::ERRORS, true)];
        }, $this->flags ?? []);
    }

    /** 1:05 */
    public static function hoursLabel(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** 20m, 1h 05m */
    public static function minutesLabel(int $minutes): string
    {
        return $minutes < 60 ? $minutes.'m' : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
