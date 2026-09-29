<?php

use App\Services\OraclePortal\SyncGuards;

/**
 * The "is this response plausible?" rules.
 *
 * These matter because the destructive actions downstream are real: a
 * truncated leave payload narrows the withdrawal window and stamps everything
 * inside it "No longer in Oracle", and a truncated employee payload would put
 * hundreds of people in front of an admin as candidate leavers.
 */
it('refuses an empty payload whatever the baseline', function () {
    // Every one of these feeds returns its whole table, so nought rows is a
    // fault, never "nothing to report".
    expect(SyncGuards::refusesPayload(0, 617))->toBeTrue();
    expect(SyncGuards::refusesPayload(0, null))->toBeTrue();
    expect(SyncGuards::refusesPayload(0, null, 0))->toBeTrue();
});

it('refuses a response under half of the last good run', function () {
    expect(SyncGuards::refusesPayload(308, 617))->toBeTrue();
    expect(SyncGuards::refusesPayload(309, 617))->toBeFalse();
});

it('accepts a response that merely shrank a little', function () {
    // People leave. A feed dropping from 617 to 600 is a normal week.
    expect(SyncGuards::refusesPayload(600, 617))->toBeFalse();
});

it('accepts growth', function () {
    expect(SyncGuards::refusesPayload(700, 617))->toBeFalse();
});

it('falls back to the floor when there is no baseline', function () {
    expect(SyncGuards::refusesPayload(120, null, 300))->toBeTrue();
    expect(SyncGuards::refusesPayload(617, null, 300))->toBeFalse();
});

it('treats a zero baseline as no baseline', function () {
    // A first successful run recorded nought would otherwise make every later
    // response acceptable.
    expect(SyncGuards::refusesPayload(120, 0, 300))->toBeTrue();
});

it('needs both the count and the share to refuse leavers', function () {
    // 26 of 617 is over the count but only 4% — a believable month.
    expect(SyncGuards::refusesLeavers(26, 617))->toBeFalse();

    // 26 of 200 is 13% — both conditions, so it is refused.
    expect(SyncGuards::refusesLeavers(26, 200))->toBeTrue();
});

it('lets a small team lose a large share of itself', function () {
    // 3 of 20 is 15%, but three people is not a broken feed.
    expect(SyncGuards::refusesLeavers(3, 20))->toBeFalse();
});

it('refuses the realistic disaster: a feed calling most people inactive', function () {
    expect(SyncGuards::refusesLeavers(600, 617))->toBeTrue();
});

it('does not divide by zero when nothing matched', function () {
    expect(SyncGuards::refusesLeavers(30, 0))->toBeFalse();
});

it('explains a refusal in a sentence', function () {
    expect(SyncGuards::payloadReason('employees', 0, 617))
        ->toContain('no employees at all');

    expect(SyncGuards::payloadReason('employees', 300, 617))
        ->toContain('300')->toContain('617');

    expect(SyncGuards::payloadReason('leave records', 120, null, 1000))
        ->toContain('first run');

    expect(SyncGuards::leaverReason(26, 200))
        ->toContain('26')->toContain('200');
});

// ─── Leave withdrawals ────────────────────────────────────────────

it('refuses a leave pull that would withdraw most of what it covers', function () {
    // The first dry run of Oracle's 2026-09-27 release: a day's shift in its
    // dates and a leave type it stopped sending.
    expect(SyncGuards::refusesWithdrawals(3285, 3299))->toBeTrue();
});

it('lets ordinary cancellations through', function () {
    // The same release once corrected: 3 of 581.
    expect(SyncGuards::refusesWithdrawals(3, 581))->toBeFalse();
    // Many, but a small share of a big window.
    expect(SyncGuards::refusesWithdrawals(40, 1000))->toBeFalse();
    // A large share, but few: a quiet book losing a handful.
    expect(SyncGuards::refusesWithdrawals(20, 30))->toBeFalse();
    expect(SyncGuards::refusesWithdrawals(0, 0))->toBeFalse();
});

it('explains a withdrawal refusal in a sentence', function () {
    expect(SyncGuards::withdrawalReason(3285, 3299))->toContain('3285')->toContain('3299');
});
