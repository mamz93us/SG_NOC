<?php

use App\Services\Vacation\VacationDays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The day counts on every leave record. Oracle's sheets carry no duration, and
 * its used days are work days — the SamirGroup book's weekend is Friday and
 * Saturday.
 */
function vacationDays(string $from, string $to): array
{
    return VacationDays::count(CarbonImmutable::parse($from), CarbonImmutable::parse($to), [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY]);
}

it('counts one day as one of each', function () {
    // 2026-09-14 is a Monday.
    expect(vacationDays('2026-09-14', '2026-09-14'))->toBe(['calendar' => 1, 'work' => 1]);
});

it('leaves the weekend out of work days but not out of calendar days', function () {
    // Monday to the Tuesday after: Friday 18 and Saturday 19 fall inside.
    expect(vacationDays('2026-09-14', '2026-09-22'))->toBe(['calendar' => 9, 'work' => 7]);
});

it('counts a weekend day as a calendar day and no work day', function () {
    expect(vacationDays('2026-09-18', '2026-09-19'))->toBe(['calendar' => 2, 'work' => 0])
        ->and(vacationDays('2026-09-17', '2026-09-20'))->toBe(['calendar' => 4, 'work' => 2]);
});

it('counts a long leave by whole weeks and the days left over', function () {
    // 84 days of maternity leave: twelve weeks of five work days.
    expect(vacationDays('2026-07-14', '2026-10-05'))->toBe(['calendar' => 84, 'work' => 60])
        ->and(vacationDays('2026-07-14', '2026-10-06'))->toBe(['calendar' => 85, 'work' => 61]);
});

it('counts every day as a work day for a book with no weekend', function () {
    expect(VacationDays::count(CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-22'), []))
        ->toBe(['calendar' => 9, 'work' => 9]);
});

it('counts nothing for a record that ends before it starts', function () {
    expect(vacationDays('2026-09-22', '2026-09-14'))->toBe(['calendar' => 0, 'work' => 0]);
});

it('refuses a weekend day that is not a day of the week', function () {
    VacationDays::count(CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-22'), [7]);
})->throws(InvalidArgumentException::class);
