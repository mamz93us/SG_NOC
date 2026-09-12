<?php

use App\Models\Attendance\AttendanceDay;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlyTotals;
use App\Services\Attendance\ShiftRule;

/**
 * The month's figures, pure — no database and no clock. A MonthlyDay is built
 * by hand here exactly as MonthlySheet builds it from the tables.
 */
function sheetShift(): ShiftRule
{
    return new ShiftRule(start: '09:00', end: '17:00', graceIn: 10, graceOut: 5, offDays: [5, 6], id: 1, name: 'Office');
}

function sheetDay(string $date, string $kind = MonthlyDay::KIND_WORK, array $row = [], int $punches = 0, bool $future = false): MonthlyDay
{
    return new MonthlyDay(
        date: $date,
        kind: $kind,
        day: $row === [] ? null : new AttendanceDay(array_merge([
            'status' => AttendanceDayBuilder::STATUS_PRESENT,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'flags' => [],
            'has_error' => false,
        ], $row)),
        punches: collect(array_fill(0, $punches, null)),
        shift: sheetShift(),
        future: $future,
    );
}

it('adds up a month of ordinary days', function () {
    $days = [
        sheetDay('2026-09-01', row: ['worked_minutes' => 480], punches: 2),
        sheetDay('2026-09-02', row: ['worked_minutes' => 500, 'overtime_minutes' => 20, 'flags' => [AttendanceDayBuilder::FLAG_OVERTIME]], punches: 2),
        sheetDay('2026-09-03', row: ['worked_minutes' => 450, 'late_minutes' => 25, 'early_leave_minutes' => 5,
            'flags' => [AttendanceDayBuilder::FLAG_LATE, AttendanceDayBuilder::FLAG_EARLY_LEAVE]], punches: 3),
        sheetDay('2026-09-04', row: ['status' => AttendanceDayBuilder::STATUS_ABSENT, 'worked_minutes' => null,
            'flags' => [AttendanceDayBuilder::FLAG_ABSENT], 'has_error' => true]),
        sheetDay('2026-09-05', MonthlyDay::KIND_OFF),
        sheetDay('2026-09-06', MonthlyDay::KIND_OFF),
    ];

    $totals = MonthlyTotals::fromDays($days);

    expect($totals->workDays)->toBe(4)
        ->and($totals->offDays)->toBe(2)
        ->and($totals->presentDays)->toBe(3)
        ->and($totals->absentDays)->toBe(1)
        ->and($totals->workedMinutes)->toBe(1430)
        ->and($totals->workedLabel())->toBe('23:50')
        ->and($totals->overtimeMinutes)->toBe(20)
        ->and($totals->lateDays)->toBe(1)
        ->and($totals->lateMinutes)->toBe(25)
        ->and($totals->earlyLeaveDays)->toBe(1)
        ->and($totals->earlyLeaveMinutes)->toBe(5)
        ->and($totals->punches)->toBe(7)
        ->and($totals->errorDays)->toBe(1);
});

it('counts a missing check-out and leaves its hours out of the total', function () {
    $days = [
        sheetDay('2026-09-01', row: ['worked_minutes' => 480], punches: 2),
        sheetDay('2026-09-02', row: ['worked_minutes' => null, 'flags' => [AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT], 'has_error' => true], punches: 1),
    ];

    $totals = MonthlyTotals::fromDays($days);

    expect($totals->missingCheckOuts)->toBe(1)
        ->and($totals->workedMinutes)->toBe(480)
        ->and($totals->presentDays)->toBe(2)
        ->and($totals->errorDays)->toBe(1);
});

it('does not count a day that has not happened yet', function () {
    $totals = MonthlyTotals::fromDays([
        sheetDay('2026-09-30', future: true),
        sheetDay('2026-10-01', MonthlyDay::KIND_OFF, future: true),
    ]);

    expect($totals->workDays)->toBe(0)
        ->and($totals->offDays)->toBe(0)
        ->and($totals->unrecordedDays)->toBe(0);
});

it('reports a past work day with no row as not recorded', function () {
    $totals = MonthlyTotals::fromDays([
        sheetDay('2026-09-01'),
        sheetDay('2026-09-02', MonthlyDay::KIND_HOLIDAY),
        sheetDay('2026-09-03', MonthlyDay::KIND_OFF),
    ]);

    expect($totals->unrecordedDays)->toBe(1)
        ->and($totals->workDays)->toBe(1)
        ->and($totals->holidayDays)->toBe(1)
        ->and($totals->offDays)->toBe(1)
        ->and($totals->absentDays)->toBe(0);
});

it('counts an excused day apart from present and absent', function () {
    $totals = MonthlyTotals::fromDays([
        sheetDay('2026-09-01', row: ['status' => AttendanceDayBuilder::STATUS_EXCUSED,
            'worked_minutes' => null, 'flags' => [AttendanceDayBuilder::FLAG_EXCUSED]]),
    ]);

    expect($totals->excusedDays)->toBe(1)
        ->and($totals->presentDays)->toBe(0)
        ->and($totals->absentDays)->toBe(0)
        ->and($totals->unrecordedDays)->toBe(0);
});

it('counts work on a day off as overtime worked off days', function () {
    $totals = MonthlyTotals::fromDays([
        sheetDay('2026-09-05', MonthlyDay::KIND_OFF, row: ['worked_minutes' => 240, 'overtime_minutes' => 240,
            'flags' => [AttendanceDayBuilder::FLAG_WORKED_OFF_DAY, AttendanceDayBuilder::FLAG_OVERTIME]], punches: 2),
    ]);

    expect($totals->workedOffDays)->toBe(1)
        ->and($totals->offDays)->toBe(1)
        ->and($totals->workDays)->toBe(0)
        ->and($totals->overtimeMinutes)->toBe(240)
        ->and($totals->presentDays)->toBe(1);
});
