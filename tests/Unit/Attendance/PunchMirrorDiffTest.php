<?php

use App\Services\Attendance\Mirror\PunchDiff;
use App\Services\Attendance\Mirror\RemovalGuard;
use App\Services\Attendance\PunchMirror;

/**
 * The two decisions the mirror rests on, without a database: which punches
 * differ, and whether the removals among them may be applied unattended.
 */
function localPunch(int $id, array $fields): array
{
    return [
        'id' => $id,
        'fingerprint' => PunchMirror::fingerprint($fields),
        'subject' => 'emp:10',
        'date' => substr($fields['punch_time'], 0, 10),
        'punch_time' => $fields['punch_time'],
        'emp_code' => $fields['emp_code'],
    ];
}

function punchFields(string $time, string $state = '0', string $code = '1001'): array
{
    return [
        'biotime_id' => 7,
        'emp_code' => $code,
        'punch_time' => $time,
        'punch_state' => $state,
        'terminal_sn' => 'SN-1',
        'terminal_alias' => 'Main gate',
        'area_alias' => 'Jeddah HQ',
    ];
}

it('tells apart a punch that is the same, changed, or new', function () {
    $same = punchFields('2026-09-09 08:55:00');
    $diff = new PunchDiff(['100' => localPunch(1, $same)]);

    expect($diff->see('100', PunchMirror::fingerprint($same))[0])->toBe(PunchDiff::SAME);

    $diff = new PunchDiff(['100' => localPunch(1, $same)]);
    [$verdict, $known] = $diff->see('100', PunchMirror::fingerprint(punchFields('2026-09-09 15:40:00')));
    expect($verdict)->toBe(PunchDiff::CHANGED)
        ->and($known['id'])->toBe(1);

    $diff = new PunchDiff([]);
    expect($diff->see('101', PunchMirror::fingerprint($same)))->toBe([PunchDiff::ADDED, null]);
});

it('counts a punch the source never offered as removed, and nothing else', function () {
    $kept = punchFields('2026-09-09 08:55:00');
    $gone = punchFields('2026-09-09 17:05:00');

    $diff = new PunchDiff([
        '100' => localPunch(1, $kept),
        '101' => localPunch(2, $gone),
    ]);

    $diff->see('100', PunchMirror::fingerprint($kept));
    $diff->see('102', PunchMirror::fingerprint(punchFields('2026-09-09 12:00:00')));   // a new one

    $removed = $diff->removed();
    expect($removed)->toHaveCount(1)
        ->and($removed[0]['id'])->toBe(2);
});

it('does not count the same source row twice when one external id arrives twice', function () {
    // The legacy CHECKINOUT table has no key of its own: two scans in one
    // second share {USERID}:{time}.
    $fields = punchFields('2026-09-09 08:55:00');
    $diff = new PunchDiff([]);

    expect($diff->see('100', PunchMirror::fingerprint($fields))[0])->toBe(PunchDiff::ADDED)
        ->and($diff->see('100', PunchMirror::fingerprint($fields))[0])->toBe(PunchDiff::SAME)
        ->and($diff->removed())->toBe([]);
});

it('fingerprints the fields the source owns, and ignores the NOC matching', function () {
    $punch = punchFields('2026-09-09 08:55:00');

    expect(PunchMirror::fingerprint($punch))->toBe(PunchMirror::fingerprint($punch + ['employee_id' => 10, 'id' => 4]))
        ->and(PunchMirror::fingerprint($punch))->not->toBe(PunchMirror::fingerprint(punchFields('2026-09-09 08:55:00', '1')))
        ->and(PunchMirror::fingerprint($punch))->not->toBe(PunchMirror::fingerprint(punchFields('2026-09-09 08:55:00', '0', '1002')));

    // A row as the database hands it back — same punch, same fingerprint.
    expect(PunchMirror::fingerprint((object) ($punch + ['punch_time' => '2026-09-09 08:55:00.000'])))
        ->toBe(PunchMirror::fingerprint($punch));
});

it('lets ordinary housekeeping through', function () {
    expect(RemovalGuard::refuse(nocRows: 400, sourceRows: 395, removals: 5))->toBeNull()
        ->and(RemovalGuard::refuse(nocRows: 5000, sourceRows: 4900, removals: 100))->toBeNull()   // 2%
        ->and(RemovalGuard::refuse(nocRows: 30, sourceRows: 6, removals: 24))->toBeNull();        // under the floor
});

it('refuses a window the source answered with nothing', function () {
    expect(RemovalGuard::refuse(nocRows: 400, sourceRows: 0, removals: 400))
        ->toContain('no punches at all');

    // And keeps refusing it however few rows are at stake — an empty answer is
    // never evidence, it is a failure that looks like one.
    expect(RemovalGuard::refuse(nocRows: 3, sourceRows: 0, removals: 3, allowBulk: true))
        ->toContain('no punches at all');
});

it('refuses a removal that is both large and a big share, until a person says so', function () {
    expect(RemovalGuard::refuse(nocRows: 200, sourceRows: 160, removals: 40))->toContain('too many')
        ->and(RemovalGuard::refuse(nocRows: 200, sourceRows: 160, removals: 40, allowBulk: true))->toBeNull();
});
