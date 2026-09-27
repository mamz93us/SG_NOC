<?php

namespace App\Services\OraclePortal;

/**
 * The "is this response plausible?" rules for the Oracle portal feeds.
 *
 * Pure and dependency-free so the thresholds can be tested without a database,
 * a network or a clock.
 *
 * The numbers match the ones the identity subsystem already defends itself
 * with — `LicenseAssignmentSync` names MAX_REMOVALS 25 and MAX_REMOVAL_SHARE
 * 0.10, refuses a user list under half the known count, and `IdentitySyncService`
 * repeats the same two inline. Those three call sites are deliberately NOT
 * changed here: they guard the Entra sync, which is the riskiest thing in the
 * app, and folding them in belongs in its own reviewed commit rather than
 * riding along with a new integration.
 *
 * The denominator is the part worth getting right. For these feeds the
 * baseline is never the NOC's own employee count: the NOC holds SSS Egypt and
 * this API does not carry them, so measuring against it would make the guard
 * meaningless. The baseline is the last successful run of the same feed, with
 * a floor for the very first run.
 */
final class SyncGuards
{
    /** A run may not suggest more than this many leavers outright … */
    public const MAX_LEAVERS = 25;

    /** … nor more than this share of the people it matched. Both must trip. */
    public const MAX_LEAVER_SHARE = 0.10;

    /** A response smaller than this share of the last good one is not believed. */
    public const MIN_PAYLOAD_SHARE = 0.50;

    /**
     * Is this response too small to act on?
     *
     * A first run has no baseline, so `$baseline` is null and only `$floor`
     * applies. An empty response is always refused: every one of these feeds
     * returns its whole table, so zero rows means a fault, never "nothing to
     * report".
     */
    public static function refusesPayload(int $received, ?int $baseline, int $floor = 0): bool
    {
        if ($received <= 0) {
            return true;
        }

        if ($baseline !== null && $baseline > 0) {
            return $received < $baseline * self::MIN_PAYLOAD_SHARE;
        }

        return $received < $floor;
    }

    /**
     * Are there so many suggested leavers that the feed is more likely broken
     * than the workforce is?
     *
     * Both conditions must hold, so a small team losing three of twenty people
     * is not blocked while a 617-row feed suddenly calling 200 people inactive
     * is.
     */
    public static function refusesLeavers(int $leavers, int $matched): bool
    {
        return $leavers > self::MAX_LEAVERS
            && $matched > 0
            && $leavers > $matched * self::MAX_LEAVER_SHARE;
    }

    /**
     * Why a payload was refused, as a sentence for a log line or a flash
     * message. `$noun` is plural and lower case: 'employees', 'announcements'.
     */
    public static function payloadReason(string $noun, int $received, ?int $baseline, int $floor = 0): string
    {
        if ($received <= 0) {
            return "Oracle returned no {$noun} at all, so nothing was changed.";
        }

        if ($baseline !== null && $baseline > 0) {
            return "Oracle returned {$received} {$noun}, under half of the {$baseline} the last run saw, "
                .'so nothing was changed.';
        }

        return "Oracle returned {$received} {$noun}, fewer than the {$floor} expected on a first run, "
            .'so nothing was changed.';
    }

    public static function leaverReason(int $leavers, int $matched): string
    {
        return "Oracle marked {$leavers} of {$matched} matched people inactive, which is too many to be "
            .'believable, so none were listed as leavers.';
    }
}
