<?php

use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\DayContext;
use App\Services\Attendance\ShiftRule;
use Carbon\CarbonImmutable;

/**
 * Shift rules, pure — no app boot, no database.
 *
 * 2026-09-10 is a Thursday. The office shift is 09:00–17:00, 10 min grace in,
 * 5 min grace out, overtime from 30 min, max 12 h, Friday and Saturday off.
 */
function officeShift(array $overrides = []): ShiftRule
{
    return new ShiftRule(...array_merge([
        'start' => '09:00', 'end' => '17:00', 'graceIn' => 10, 'graceOut' => 5,
        'maxMinutes' => 720, 'minOvertime' => 30, 'offDays' => [5, 6],
    ], $overrides));
}

function nightShift(array $overrides = []): ShiftRule
{
    return new ShiftRule(...array_merge([
        'start' => '22:00', 'end' => '06:00', 'graceIn' => 10, 'graceOut' => 5,
        'maxMinutes' => 720, 'minOvertime' => 30, 'offDays' => [],
    ], $overrides));
}

function shiftDay(array $punches, DayContext $context, string $date = '2026-09-10', string $now = '2026-09-10 23:00:00')
{
    return (new AttendanceDayBuilder)->build($date, $punches, CarbonImmutable::parse($now), [], $context);
}

it('is not late inside the grace period', function () {
    $day = shiftDay(['2026-09-10 09:08:00', '2026-09-10 17:02:00'], new DayContext(shift: officeShift()));

    expect($day->lateMinutes)->toBe(0)
        ->and($day->earlyLeaveMinutes)->toBe(0)
        ->and($day->overtimeMinutes)->toBe(0)
        ->and($day->flags)->toBe([])
        ->and($day->status)->toBe(AttendanceDayBuilder::STATUS_PRESENT)
        ->and($day->scheduledStart)->toBe('2026-09-10 09:00:00');
});

it('counts lateness from the shift start once past the grace', function () {
    $day = shiftDay(['2026-09-10 09:20:00', '2026-09-10 17:00:00'], new DayContext(shift: officeShift()));

    expect($day->lateMinutes)->toBe(20)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_LATE])
        ->and($day->hasError())->toBeFalse();
});

it('counts early leave up to the shift end', function () {
    $day = shiftDay(['2026-09-10 09:00:00', '2026-09-10 16:30:00'], new DayContext(shift: officeShift()));

    expect($day->earlyLeaveMinutes)->toBe(30)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_EARLY_LEAVE]);
});

it('counts overtime after the shift end only once it reaches the minimum', function () {
    $long = shiftDay(['2026-09-10 09:00:00', '2026-09-10 18:10:00'], new DayContext(shift: officeShift()));
    $short = shiftDay(['2026-09-10 09:00:00', '2026-09-10 17:20:00'], new DayContext(shift: officeShift()));

    expect($long->overtimeMinutes)->toBe(70)
        ->and($long->flags)->toBe([AttendanceDayBuilder::FLAG_OVERTIME])
        ->and($short->overtimeMinutes)->toBe(0);
});

it('makes every worked minute on a day off overtime, with no lateness', function () {
    $day = shiftDay(['2026-09-11 10:00:00', '2026-09-11 14:00:00'], new DayContext(shift: officeShift()), date: '2026-09-11');

    expect($day->overtimeMinutes)->toBe(240)
        ->and($day->lateMinutes)->toBe(0)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_WORKED_OFF_DAY, AttendanceDayBuilder::FLAG_OVERTIME);
});

it('makes every worked minute on a holiday overtime', function () {
    $day = shiftDay(['2026-09-10 09:00:00', '2026-09-10 13:00:00'], new DayContext(shift: officeShift(), holiday: 'National Day'));

    expect($day->overtimeMinutes)->toBe(240)
        ->and($day->earlyLeaveMinutes)->toBe(0)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_WORKED_HOLIDAY);
});

it('records an absence on a work day with no punches once the shift is over', function () {
    $day = shiftDay([], new DayContext(shift: officeShift(), expectedToWork: true));

    expect($day->status)->toBe(AttendanceDayBuilder::STATUS_ABSENT)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_ABSENT])
        ->and($day->hasError())->toBeTrue();
});

it('does not call someone absent before their shift has ended', function () {
    $day = shiftDay([], new DayContext(shift: officeShift(), expectedToWork: true), now: '2026-09-10 16:00:00');

    expect($day->status)->toBe(AttendanceDayBuilder::STATUS_NONE);
});

it('does not call someone absent on a day off, a holiday, or when they are not expected', function () {
    $dayOff = shiftDay([], new DayContext(shift: officeShift(), expectedToWork: true), date: '2026-09-11', now: '2026-09-12 12:00:00');
    $holiday = shiftDay([], new DayContext(shift: officeShift(), holiday: 'National Day', expectedToWork: true));
    $notExpected = shiftDay([], new DayContext(shift: officeShift(), expectedToWork: false));
    $noShift = shiftDay([], new DayContext(expectedToWork: true));

    expect($dayOff->status)->toBe(AttendanceDayBuilder::STATUS_NONE)
        ->and($holiday->status)->toBe(AttendanceDayBuilder::STATUS_NONE)
        ->and($notExpected->status)->toBe(AttendanceDayBuilder::STATUS_NONE)
        ->and($noShift->status)->toBe(AttendanceDayBuilder::STATUS_NONE);
});

it('turns an absence into an excused day', function () {
    $day = shiftDay([], new DayContext(shift: officeShift(), expectedToWork: true, excuse: 'annual_leave'));

    expect($day->status)->toBe(AttendanceDayBuilder::STATUS_EXCUSED)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_EXCUSED])
        ->and($day->excuse)->toBe('annual_leave')
        ->and($day->hasError())->toBeFalse();
});

it('lets an excuse settle lateness and a missing check-out', function () {
    $day = shiftDay(['2026-09-10 10:30:00'], new DayContext(shift: officeShift(), excuse: 'mission'));

    expect($day->status)->toBe(AttendanceDayBuilder::STATUS_EXCUSED)
        ->and($day->lateMinutes)->toBe(0)
        ->and($day->flags)->not->toContain(AttendanceDayBuilder::FLAG_LATE)
        ->and($day->flags)->not->toContain(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)
        ->and($day->hasError())->toBeFalse();
});

it('fills a missing check-out from an HR correction', function () {
    $day = shiftDay(['2026-09-10 09:00:00'], new DayContext(shift: officeShift(), checkOut: '2026-09-10 17:00:00'));

    expect($day->lastOut)->toBe('2026-09-10 17:00:00')
        ->and($day->workedMinutes)->toBe(480)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_ADJUSTED])
        ->and($day->hasError())->toBeFalse();
});

it('treats more than the shift maximum as a forgotten check-out', function () {
    $day = shiftDay(['2026-09-10 07:00:00', '2026-09-10 21:00:00'], new DayContext(shift: officeShift()));

    expect($day->flags)->toContain(AttendanceDayBuilder::FLAG_OVER_MAX_HOURS)
        ->and($day->hasError())->toBeTrue();
});

it('uses 16 hours as the maximum when there is no shift', function () {
    $day = shiftDay(['2026-09-10 06:00:00', '2026-09-10 22:30:00'], new DayContext);

    expect($day->flags)->toContain(AttendanceDayBuilder::FLAG_OVER_MAX_HOURS);
});

it('keeps an overnight shift as one day across midnight', function () {
    $day = shiftDay(
        ['2026-09-10 21:55:00', '2026-09-11 06:10:00'],
        new DayContext(shift: nightShift()),
        now: '2026-09-11 12:00:00',
    );

    expect($day->firstIn)->toBe('2026-09-10 21:55:00')
        ->and($day->lastOut)->toBe('2026-09-11 06:10:00')
        ->and($day->workedMinutes)->toBe(495)
        ->and($day->lateMinutes)->toBe(0)
        ->and($day->overtimeMinutes)->toBe(0)
        ->and($day->scheduledEnd)->toBe('2026-09-11 06:00:00')
        ->and($day->flags)->toBe([]);
});

it('uses the calendar day as the window without an overnight shift', function () {
    [$start, $end] = AttendanceDayBuilder::window('2026-09-10', officeShift());

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-10 00:00')
        ->and($end->format('Y-m-d H:i'))->toBe('2026-09-11 00:00');
});

it('runs an overnight shift window on past midnight', function () {
    [$start, $end] = AttendanceDayBuilder::window('2026-09-10', nightShift());

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-10 00:00')
        ->and($end->format('Y-m-d H:i'))->toBe('2026-09-11 12:00');
});

it('starts the next day where an overnight shift ended', function () {
    [$start] = AttendanceDayBuilder::window('2026-09-11', officeShift(), nightShift());

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-11 12:00');
});

it('does not let an overnight shift on a day off claim the next morning', function () {
    [$start] = AttendanceDayBuilder::window('2026-09-11', officeShift(), nightShift(['offDays' => [4]]));

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-11 00:00');
});
