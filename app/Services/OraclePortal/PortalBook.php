<?php

namespace App\Services\OraclePortal;

use App\Models\Branch;
use RuntimeException;

/**
 * Which NOC employees this feed is allowed to be about.
 *
 * The Employee Portal API serves one Oracle book — SamirGroup Saudi Arabia.
 * Every row it returns is in Riyadh, Jeddah, Al-Khobar or Abha; SSS Egypt is
 * not in it, and the two EMP_NO series collide, so number 1655 can name a
 * Saudi employee here and a different Egyptian one in the NOC.
 *
 * This class is the structural answer to that: a Cairo employee is never a
 * candidate for a match, never a suggested leaver, and never touched by an
 * apply. Filtering a list at the end would leave the collision live in every
 * step before it.
 *
 * {@see \App\Services\Vacation\VacationLinker} does the same job for leave and
 * deliberately degrades to "any holder" when the config names no branch, so a
 * stale config links people rather than nobody. That is right for a balance
 * and wrong here: this feed also proposes who has LEFT, and degrading would
 * put every Egyptian employee in front of an admin as a candidate leaver. So
 * this one refuses instead.
 */
class PortalBook
{
    /** @var list<int>|null */
    private ?array $branchIds = null;

    /**
     * The book's branch ids.
     *
     * These are branch NAMES in config, because `branches` has no code column
     * — the same shape VacationLinker::branchIdsOf() matches on.
     *
     * @return list<int>
     *
     * @throws RuntimeException when the config names nothing, or nothing real
     */
    public function branchIds(): array
    {
        if ($this->branchIds !== null) {
            return $this->branchIds;
        }

        $names = (array) config('oracle_portal.book.branches', []);

        if ($names === []) {
            throw new RuntimeException(
                'config/oracle_portal.php names no branches for the book, so there is no way to tell a '
                .'SamirGroup employee from an SSS Egypt one holding the same Oracle number.'
            );
        }

        $ids = Branch::query()->whereIn('name', $names)->pluck('id')
            ->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === []) {
            throw new RuntimeException(
                'None of the branches named in config/oracle_portal.php ('.implode(', ', $names).') exist. '
                .'Check the names against the Branches page — they are names, not codes.'
            );
        }

        return $this->branchIds = $ids;
    }

    /**
     * May this employee be matched, proposed or written by this feed?
     *
     * An employee with no branch at all is allowed: a record staged from a
     * previous import may not have resolved one yet, and refusing those would
     * quietly strand people the feed genuinely covers. The Oracle number is
     * what disambiguates them, and `matchEmployee()` already needs two signals
     * to agree before it commits to anyone.
     */
    public function allows(?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }

        return in_array($branchId, $this->branchIds(), true);
    }

    /** Branch names configured for the book, for messages and notes. */
    public function branchNames(): array
    {
        return array_values((array) config('oracle_portal.book.branches', []));
    }

    public function label(): string
    {
        return (string) config('oracle_portal.book.label', 'SamirGroup');
    }

    /** The key used by config/vacations.php for the same body of people. */
    public function key(): string
    {
        return (string) config('oracle_portal.book.key', 'samirgroup');
    }
}
