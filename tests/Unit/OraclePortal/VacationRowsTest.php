<?php

use App\Services\OraclePortal\VacationRows;

/**
 * Oracle's leave JSON onto the importer's row keys.
 *
 * The shapes below are the live API's, checked against production on
 * 2026-09-20. What is defended here is the handful of things that would be
 * silently wrong rather than loudly broken: the sign of `absences`, a figure
 * Oracle omits rather than sends as null, and the person-id map coming from
 * the one endpoint that actually carries person ids.
 *
 * No database and no app boot — these are pure functions, which is the whole
 * reason they exist as a separate class: the API is unreachable from a
 * developer machine, so this is where the mapping is actually verified.
 */
it('maps a balance row onto the importer keys', function () {
    $rows = VacationRows::balances([
        ['personNumber' => '166', 'carryover' => 10, 'accruals' => 14.67, 'absences' => -2, 'totalBalance' => 22.67],
    ]);

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'person_number' => '166',
        'person_id' => null,
        'carryover' => 10,
        'accrued' => 14.67,
        'balance' => 22.67,
    ]);
});

it('passes Oracle\'s negative absences through untouched', function () {
    // The importer calls negated() on this key and stores a positive `used`.
    // Flipping the sign here would zero every person's used days instead.
    $rows = VacationRows::balances([
        ['personNumber' => '1003', 'carryover' => 8.38, 'accruals' => 14.67, 'absences' => -23, 'totalBalance' => 0.05],
    ]);

    expect($rows[0]['absences'])->toBe(-23);
});

it('reads a figure Oracle omits as null, not as nought', function () {
    // Null fields are left out of the JSON entirely: carryover is present on
    // 536 of 602 rows. Nought would be a claim Oracle never made.
    $rows = VacationRows::balances([
        ['personNumber' => '2331', 'accruals' => 14.67, 'totalBalance' => 14.67],
    ]);

    expect($rows[0]['carryover'])->toBeNull();
    expect($rows[0]['absences'])->toBeNull();
    expect($rows[0]['accrued'])->toBe(14.67);
});

it('fills person_id from the id map and leaves it null without one', function () {
    $rows = VacationRows::balances(
        [
            ['personNumber' => '1003', 'totalBalance' => 0.05],
            ['personNumber' => '9999', 'totalBalance' => 1.0],
        ],
        ['1003' => '100000000367262'],
    );

    expect($rows[0]['person_id'])->toBe('100000000367262');
    expect($rows[1]['person_id'])->toBeNull();
});

it('maps a leave record onto the importer keys', function () {
    $rows = VacationRows::absences([
        ['personNumber' => '2331', 'absenceType' => 'Internal Business Trip', 'startDate' => '09-SEP-26', 'endDate' => '09-SEP-26'],
    ]);

    expect($rows[0])->toMatchArray([
        'person_number' => '2331',
        'type' => 'Internal Business Trip',
        'start' => '09-SEP-26',
        'end' => '09-SEP-26',
    ]);
});

it('leaves the dates exactly as Oracle wrote them', function () {
    // dd-MMM-yy is the importer's job to parse — it already accepts !d-M-y,
    // and the spreadsheet path relies on the same parser. A second parser here
    // is a second thing that can disagree about the same person's leave.
    $rows = VacationRows::absences([
        ['personNumber' => '166', 'absenceType' => 'Annual Leave', 'startDate' => '13-AUG-26', 'endDate' => '24-AUG-26'],
    ]);

    expect($rows[0]['start'])->toBe('13-AUG-26');
    expect($rows[0]['end'])->toBe('24-AUG-26');
});

it('sends no duration, because Oracle sends none', function () {
    $rows = VacationRows::absences([
        ['personNumber' => '166', 'absenceType' => 'Sick Leave', 'startDate' => '10-SEP-26', 'endDate' => '10-SEP-26'],
    ]);

    // The importer prefers a supplied duration over its own work-day count, so
    // an invented one would quietly override the counted days.
    expect($rows[0])->not->toHaveKey('duration');
});

it('builds the person-id map from an attendance payload', function () {
    $map = VacationRows::personIds([
        ['personNumber' => '1003', 'personId' => '100000000367262'],
        ['personNumber' => '1969', 'personId' => '300000329720317'],
    ]);

    expect($map)->toBe(['1003' => '100000000367262', '1969' => '300000329720317']);
});

it('yields an empty map from an employees payload, which carries no person id', function () {
    // /employees looks like the cheaper source — 213 KB against 381 KB — but
    // it has no personId column at all. Failing soft here means the balances
    // still import; they just carry no Oracle person id.
    $map = VacationRows::personIds([
        ['personNumber' => '1003', 'personName' => 'Someone', 'status' => 'ACTIVE'],
    ]);

    expect($map)->toBe([]);
});

it('treats a blank person number as absent rather than as an empty string', function () {
    $rows = VacationRows::balances([['personNumber' => '   ', 'totalBalance' => 1]]);

    expect($rows[0]['person_number'])->toBeNull();
});
