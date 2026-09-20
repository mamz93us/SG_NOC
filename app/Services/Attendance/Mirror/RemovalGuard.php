<?php

namespace App\Services\Attendance\Mirror;

/**
 * Whether a run may stamp punches as removed, or has to report and stop.
 *
 * Removing is the one thing the mirror does that loses something, and it runs
 * unattended every night. Two things look exactly like "the source deleted
 * these punches" and are not: a database restored, repointed or empty, and a
 * BioTime installation whose own retention has pruned punches the NOC is
 * keeping. So a run that would clear the window, or take a large share of it,
 * says so instead and waits for a person.
 *
 * Pure. The same shape as the Microsoft licence sync's guards: an absolute
 * floor AND a share, so a handful on a quiet day still goes through and a
 * month of history never does.
 */
class RemovalGuard
{
    /** Below this many, a removal is ordinary housekeeping. */
    public const FLOOR = 25;

    /** And it must also be under this share of the window's punches. */
    public const SHARE = 0.10;

    /** @return string|null the reason to refuse, or null to go ahead */
    public static function refuse(int $nocRows, int $sourceRows, int $removals, bool $allowBulk = false): ?string
    {
        if ($removals < 1) {
            return null;
        }

        // Nothing came back at all. Whatever that is — a table renamed, a
        // restore mid-flight, a window the source has pruned — it is not an
        // instruction to empty the NOC's copy.
        if ($sourceRows < 1) {
            return "the source returned no punches at all for this window, so all {$nocRows} here would be removed";
        }

        if ($allowBulk) {
            return null;
        }

        if ($removals >= self::FLOOR && $removals >= $nocRows * self::SHARE) {
            return sprintf(
                '%d of the %d punches in this window are gone at the source (%s%%) — too many to stamp unattended',
                $removals,
                $nocRows,
                $nocRows > 0 ? number_format($removals / $nocRows * 100, 1) : '100',
            );
        }

        return null;
    }
}
