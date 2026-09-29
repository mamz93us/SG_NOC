<?php

use App\Services\OraclePortal\DateOffset;
use Carbon\CarbonImmutable;

/**
 * Oracle's API release of 2026-09-27 writes hire and leave dates a day early.
 * DateOffset judges that from leave start dates, which never fall on the Saudi
 * weekend: 0.4% did once moved a day later, 41% as sent.
 */
const SAUDI_WEEKEND = [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY];

/** $count leave start dates on working days, moved by $shift the way Oracle sends them. */
function leaveStarts(int $count, int $shift = 0): array
{
    $dates = [];
    $day = CarbonImmutable::parse('2026-08-02'); // a Sunday

    while (count($dates) < $count) {
        if (! in_array($day->dayOfWeek, SAUDI_WEEKEND, true)) {
            $dates[] = $day->addDays($shift)->toDateString();
        }
        $day = $day->addDay();
    }

    return $dates;
}

it('finds Oracle\'s dates a day early and says to add one', function () {
    expect(DateOffset::detect(leaveStarts(300, -1), SAUDI_WEEKEND))->toBe(1);
});

it('adds nothing the day Oracle fixes its dates', function () {
    expect(DateOffset::detect(leaveStarts(300), SAUDI_WEEKEND))->toBe(0);
});

it('tolerates the odd trip that does start on a weekend', function () {
    $dates = array_merge(leaveStarts(300, -1), ['2026-08-06', '2026-08-13']); // Thursdays, read as Fridays

    expect(DateOffset::detect($dates, SAUDI_WEEKEND))->toBe(1);
});

it('refuses to judge from too few records', function () {
    expect(fn () => DateOffset::detect(leaveStarts(20, -1), SAUDI_WEEKEND))
        ->toThrow(RuntimeException::class, 'too few');
});

it('refuses when no offset takes leave off the weekend', function () {
    // Every day of the week alike: a feed whose dates are simply wrong.
    $dates = [];
    for ($i = 0; $i < 140; $i++) {
        $dates[] = CarbonImmutable::parse('2026-08-01')->addDays($i)->toDateString();
    }

    expect(fn () => DateOffset::detect($dates, SAUDI_WEEKEND))
        ->toThrow(RuntimeException::class, 'do not line up');
});

it('skips anything that is not a yyyy-MM-dd date', function () {
    $shares = DateOffset::weekendShares(['2026-08-01', '01-AUG-26', null, 42, '2026-02-31'], SAUDI_WEEKEND);

    expect($shares['sample'])->toBe(1)
        ->and($shares[0])->toBe(1.0) // a Saturday
        ->and($shares[1])->toBe(0.0); // a Sunday once moved
});

it('shifts only the named date fields, and leaves anything else as it was', function () {
    $rows = DateOffset::shift([
        ['startDate' => '2026-08-01', 'endDate' => '2026-08-31', 'personNumber' => '2026-01-01'],
        ['startDate' => '01-AUG-26', 'endDate' => '2026-02-31'],
        ['startDate' => '2026-12-31'],
    ], ['startDate', 'endDate'], 1);

    expect($rows)->toBe([
        ['startDate' => '2026-08-02', 'endDate' => '2026-09-01', 'personNumber' => '2026-01-01'],
        ['startDate' => '01-AUG-26', 'endDate' => '2026-02-31'],
        ['startDate' => '2027-01-01'],
    ]);
});

it('changes nothing when the offset is nought', function () {
    $rows = [['startDate' => '2026-08-01']];

    expect(DateOffset::shift($rows, ['startDate'], 0))->toBe($rows);
});
