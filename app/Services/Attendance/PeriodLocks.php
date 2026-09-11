<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendancePeriod;

/**
 * Which (branch, date) pairs sit in an approved period — the processor leaves
 * those days exactly as they were approved.
 *
 * Kept in memory for a rebuild, refreshed after a minute so a long sync picks
 * up an approval made while it ran. The database row's own `locked` flag is
 * the final guard either way.
 */
class PeriodLocks
{
    private const TTL_SECONDS = 60;

    /**
     * @param  list<array{branch: ?int, from: string, to: string}>  $periods
     */
    private function __construct(private array $periods, private float $loadedAt) {}

    public static function load(): self
    {
        $periods = AttendancePeriod::query()
            ->whereIn('status', AttendancePeriod::LOCKING)
            ->get(['branch_id', 'date_from', 'date_to'])
            ->map(fn (AttendancePeriod $p) => [
                'branch' => $p->branch_id,
                'from' => $p->date_from->toDateString(),
                'to' => $p->date_to->toDateString(),
            ])
            ->all();

        return new self($periods, microtime(true));
    }

    public function isStale(): bool
    {
        return microtime(true) - $this->loadedAt > self::TTL_SECONDS;
    }

    public function covers(?int $branchId, string $date): bool
    {
        foreach ($this->periods as $period) {
            if ($date < $period['from'] || $date > $period['to']) {
                continue;
            }
            if ($period['branch'] === null || $period['branch'] === $branchId) {
                return true;
            }
        }

        return false;
    }
}
