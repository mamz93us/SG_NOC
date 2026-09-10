<?php

use App\Services\Attendance\AttendanceDayBuilder;
use Carbon\CarbonImmutable;

/**
 * Pure: no app boot, no database. The rule the owner set is "earliest punch of
 * the day = check-in, latest punch = check-out, for every user".
 */
function attendanceDay(array $punches, string $now = '2026-09-10 23:00:00', array $extraFlags = [])
{
    return (new AttendanceDayBuilder)->build('2026-09-10', $punches, CarbonImmutable::parse($now), $extraFlags);
}

it('uses the earliest punch as check-in and the latest as check-out', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 12:30:00', '2026-09-10 13:10:00', '2026-09-10 17:05:00']);

    expect($day->firstIn)->toBe('2026-09-10 08:55:00')
        ->and($day->lastOut)->toBe('2026-09-10 17:05:00')
        ->and($day->workedMinutes)->toBe(490)
        ->and($day->punchCount)->toBe(4)
        ->and($day->flags)->toBe([])
        ->and($day->hasError())->toBeFalse();
});

it('does not depend on the order punches arrive in', function () {
    $day = attendanceDay(['2026-09-10 17:05:00', '2026-09-10 08:55:00', '2026-09-10 13:10:00']);

    expect($day->firstIn)->toBe('2026-09-10 08:55:00')
        ->and($day->lastOut)->toBe('2026-09-10 17:05:00');
});

it('keeps a single punch as check-in and flags the missing check-out', function () {
    $day = attendanceDay(['2026-09-10 08:55:00']);

    expect($day->firstIn)->toBe('2026-09-10 08:55:00')
        ->and($day->lastOut)->toBeNull()
        ->and($day->workedMinutes)->toBeNull()
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)
        ->and($day->hasError())->toBeTrue();
});

it('collapses a repeat touch within two minutes into one punch', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 08:55:40', '2026-09-10 17:05:00']);

    expect($day->firstIn)->toBe('2026-09-10 08:55:00')
        ->and($day->lastOut)->toBe('2026-09-10 17:05:00')
        ->and($day->punchCount)->toBe(3)
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_DUPLICATES])
        ->and($day->hasError())->toBeFalse();
});

it('treats two touches seconds apart as one punch, so the check-out is missing', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 08:56:30']);

    expect($day->lastOut)->toBeNull()
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_DUPLICATES)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT);
});

it('counts two punches exactly two minutes apart as separate', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 08:57:00']);

    expect($day->lastOut)->toBe('2026-09-10 08:57:00')
        ->and($day->flags)->toBe([]);
});

it('flags a punch from the future as an error', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 17:05:00'], now: '2026-09-10 12:00:00');

    expect($day->flags)->toContain(AttendanceDayBuilder::FLAG_FUTURE_PUNCH)
        ->and($day->hasError())->toBeTrue();
});

it('allows a device clock a few minutes ahead', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 17:05:00'], now: '2026-09-10 17:00:00');

    expect($day->flags)->not->toContain(AttendanceDayBuilder::FLAG_FUTURE_PUNCH);
});

it('makes an unmapped code a data error', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 17:05:00'], extraFlags: [AttendanceDayBuilder::FLAG_UNMAPPED]);

    expect($day->hasError())->toBeTrue();
});

it('does not treat a confirmed non-employee as an error', function () {
    $day = attendanceDay(['2026-09-10 08:55:00', '2026-09-10 17:05:00'], extraFlags: [AttendanceDayBuilder::FLAG_NOT_EMPLOYEE]);

    expect($day->flags)->toBe([AttendanceDayBuilder::FLAG_NOT_EMPLOYEE])
        ->and($day->hasError())->toBeFalse();
});
