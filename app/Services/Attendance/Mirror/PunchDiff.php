<?php

namespace App\Services\Attendance\Mirror;

/**
 * One window of punches, NOC side against source side.
 *
 * The source is read page by page, so the comparison streams: the NOC's rows
 * for the window are loaded once, each source row is offered to see(), and
 * whatever was never offered is what the source no longer holds.
 *
 * Pure — no database, no clock. PunchMirror does the reading and the writing.
 */
class PunchDiff
{
    /** The source has a punch the NOC does not. */
    public const ADDED = 'added';

    /** Both have it, and a field the source owns is different. */
    public const CHANGED = 'changed';

    public const SAME = 'same';

    /**
     * The NOC's punches for the window, keyed by external_id.
     *
     * @param  array<string, array{id: int, fingerprint: string, subject: string, date: string}>  $local
     */
    public function __construct(private array $local) {}

    /**
     * What the source's version of this punch means for the NOC's copy.
     *
     * @return array{0: string, 1: array{id: int, fingerprint: string, subject: string, date: string}|null}
     *                                                                                                      the verdict, and the NOC row it matched
     */
    public function see(string $externalId, string $fingerprint): array
    {
        $known = $this->local[$externalId] ?? null;

        // The same punch twice in one pass — the legacy CHECKINOUT table has
        // no key of its own, so two scans in one second share an external id.
        // The first occurrence already decided what happens to the row.
        if ($known !== null && ($known['seen'] ?? false)) {
            return [self::SAME, null];
        }

        // Marks a known row seen, and leaves a seen-only stub for a new one,
        // so removed() cannot mistake either for a punch the source dropped.
        $this->local[$externalId]['seen'] = true;

        if ($known === null) {
            return [self::ADDED, null];
        }

        return [$known['fingerprint'] === $fingerprint ? self::SAME : self::CHANGED, $known];
    }

    /**
     * The NOC's punches the source never offered — deleted there.
     *
     * @return list<array{id: int, fingerprint: string, subject: string, date: string}>
     */
    public function removed(): array
    {
        return array_values(array_filter(
            $this->local,
            fn (array $row) => ! ($row['seen'] ?? false) && isset($row['id']),
        ));
    }
}
