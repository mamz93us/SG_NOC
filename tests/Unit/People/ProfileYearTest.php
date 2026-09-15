<?php

use App\Models\Attendance\AttendanceDay;
use App\Models\Vacation\VacationAbsence;
use App\Services\Attendance\MonthlyDay;
use App\Services\People\ProfileYear;

/**
 * The profile's year: attendance exactly as the monthly sheet counts it, with
 * Oracle's leave laid over the days. September 2026 — the 1st is a Tuesday,
 * and Friday and Saturday are days off.
 */
uses(Tests\TestCase::class);

function profileDay(string $date, string $kind = MonthlyDay::KIND_WORK, ?string $status = null, bool $future = false): MonthlyDay
{
    $row = null;

    if ($status !== null) {
        $row = new AttendanceDay;
        $row->forceFill([
            'id' => (int) str_replace('-', '', $date),
            'work_date' => $date,
            'status' => $status,
            'worked_minutes' => $status === 'present' ? 480 : null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'flags' => [],
            'has_error' => false,
        ]);
    }

    return new MonthlyDay(date: $date, kind: $kind, day: $row, punches: collect(), future: $future);
}

function profileLeave(string $type, string $from, string $to, bool $withdrawn = false): VacationAbsence
{
    $record = new VacationAbsence;
    $record->forceFill([
        'absence_type' => $type,
        'start_date' => $from,
        'end_date' => $to,
        'removed_at' => $withdrawn ? '2026-09-10 10:00:00' : null,
    ]);

    return $record;
}

it('counts leave on work days only, and an absence Oracle covers as away but still to excuse', function () {
    $year = ProfileYear::build([
        profileDay('2026-09-01', status: 'absent'),
        profileDay('2026-09-02', status: 'absent'),
        profileDay('2026-09-03', status: 'present'),
        profileDay('2026-09-04', MonthlyDay::KIND_OFF),
        profileDay('2026-09-05', MonthlyDay::KIND_OFF),
        profileDay('2026-09-06'),
        profileDay('2026-09-07', status: 'excused'),
        profileDay('2026-09-08', MonthlyDay::KIND_HOLIDAY),
    ], [
        profileLeave('Annual Leave', '2026-09-01', '2026-09-05'),
        profileLeave('Internal Business Trip', '2026-09-06', '2026-09-06'),
    ]);

    $september = $year->months['2026-09'];

    expect(array_keys($year->months))->toBe(['2026-09'])
        ->and($september->leaveDays)->toBe(3)                 // Tue–Thu; Friday and Saturday are off
        ->and($september->tripDays)->toBe(1)
        ->and($september->awayDays)->toBe(4)                  // two covered absences, the trip day, the excused day
        ->and($september->absentDays)->toBe(0)
        ->and($september->absentOnLeaveDays)->toBe(2)
        ->and($september->notRecordedDays)->toBe(0)           // the holiday was not a work day
        ->and($september->leaveByType)->toBe(['Annual Leave' => 3])
        ->and($september->attendance->presentDays)->toBe(1)   // the monthly sheet's own figures
        ->and($september->attendance->absentDays)->toBe(2)
        ->and($september->attendance->workDays)->toBe(5)
        ->and($september->attendanceRate())->toBe(1.0)
        ->and(array_column($year->absentOnLeave, 'date'))->toBe(['2026-09-01', '2026-09-02'])
        ->and($year->absentOnLeave[0]['type'])->toBe('Annual Leave')
        ->and($year->absentOnLeave[0]['trip'])->toBeFalse()
        ->and($year->presentOnLeave)->toBe([['date' => '2026-09-03', 'type' => 'Annual Leave', 'day_id' => 20260903]]);
});

it('keeps an absence nothing covers absent, ignores withdrawn leave, and counts booked leave without judging the days', function () {
    $year = ProfileYear::build([
        profileDay('2026-09-13', status: 'absent'),
        profileDay('2026-09-14'),
        profileDay('2026-09-29', future: true),
        profileDay('2026-09-30', future: true),
    ], [
        profileLeave('Annual Leave', '2026-09-13', '2026-09-13', withdrawn: true),
        profileLeave('Annual Leave', '2026-09-29', '2026-09-30'),
    ]);

    $september = $year->months['2026-09'];

    expect($september->absentDays)->toBe(1)
        ->and($september->absentOnLeaveDays)->toBe(0)
        ->and($september->notRecordedDays)->toBe(1)
        ->and($september->leaveDays)->toBe(2)
        ->and($september->awayDays)->toBe(0)
        ->and($september->attendanceRate())->toBe(0.0)
        ->and($year->absentOnLeave)->toBe([]);
});

it('uses the book\'s weekend for a person with no shift, and adds the months up into the year', function () {
    $year = ProfileYear::build([
        profileDay('2026-08-31', MonthlyDay::KIND_NO_SHIFT),
        profileDay('2026-09-04', MonthlyDay::KIND_NO_SHIFT),
        profileDay('2026-09-06', MonthlyDay::KIND_NO_SHIFT),
    ], [profileLeave('Sick Leave', '2026-08-30', '2026-09-06')], [5, 6]);

    expect($year->months['2026-08']->leaveDays)->toBe(1)      // Monday the 31st; the 30th is outside the days given
        ->and($year->months['2026-09']->leaveDays)->toBe(1)   // Sunday the 6th; Friday the 4th is the weekend
        ->and($year->months['2026-09']->notRecordedDays)->toBe(0)
        ->and($year->months['2026-09']->attendanceRate())->toBeNull()
        ->and($year->total->month)->toBe('2026')
        ->and($year->total->leaveDays)->toBe(2)
        ->and($year->total->leaveByType)->toBe(['Sick Leave' => 2]);
});

it('lays each active record over the dates it covers, inside the range asked for', function () {
    $coverage = ProfileYear::coverage([
        profileLeave('Annual Leave', '2026-08-30', '2026-09-02'),
        profileLeave('Sick Leave', '2026-09-02', '2026-09-02'),
        profileLeave('Annual Leave', '2026-09-01', '2026-09-03', withdrawn: true),
    ], '2026-09-01', '2026-09-30');

    expect(array_keys($coverage))->toBe(['2026-09-01', '2026-09-02'])
        ->and(array_map(fn ($record) => $record->absence_type, $coverage['2026-09-02']))->toBe(['Annual Leave', 'Sick Leave']);
});
