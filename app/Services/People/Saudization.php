<?php

namespace App\Services\People;

use App\Models\Employee;
use App\Models\SaudizationGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where each professional group stands against the Saudization percentage the
 * ministry requires of it.
 *
 * **This is a head count, and the ministry's is not.** A group's share here is
 * Saudis ÷ everybody in it, from the nationality and job category Oracle holds
 * for each current employee. The ministry (Qiwa) weighs people — a part-timer
 * or a low salary counts for less, some non-Saudis count as Saudis (the husband
 * or son of a citizen), and a rule may not apply below a number of staff in the
 * profession. On 2026-10-05 the two agreed on 14 of HR's 17 groups; the page
 * says which kind of count it is rather than pass for the official one.
 *
 * Counted: current staff, one row per person (a linked second mailbox is the
 * same person), in the group's Oracle job category. Somebody whose nationality
 * is not known is counted in the group and not as a Saudi, and reported — a
 * missing nationality must not make a group look better than it is.
 *
 * **Somebody in no professional group is not counted at all** — not as a
 * Saudi and not as anything else. Drivers, warehouse staff and everybody else
 * whose job category no group counts (136 of 606 on 2026-10-06) have no
 * percentage to be held to, so they are in no figure here: not a group's, not
 * a department's, not the company's share. The first version put them in the
 * company's share (258 of 606) and in a table of their own with a Saudi count,
 * and HR's reading was the plain one: they are neither here nor there. The
 * pages say how many they are in one line, so the head count still accounts
 * for everybody, and nothing more.
 *
 * Nothing is stored. Every figure is today's, so a hire shows the same day.
 */
class Saudization
{
    /** How Oracle writes the nationality that counts. Compared without case. */
    public const SAUDI = 'Saudi';

    /**
     * @return array{
     *     groups: Collection<int, array<string,mixed>>,
     *     overall: array{people:int, saudis:int, unknown:int, share:?float, short_by:int},
     *     uncounted: array{people:int, categories: list<array{job_category: ?string, people:int}>},
     *     compliant: int, not_compliant: int, empty: int
     * }
     */
    public function report(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = $this->counts();
        $groups = SaudizationGroup::query()->orderBy('sort_order')->orderBy('id')->get();
        $claimed = $groups->pluck('job_category')->filter()->map(fn ($c) => mb_strtolower($c))->flip();

        $rows = $groups->map(fn (SaudizationGroup $group) => $this->row($group, $counts, $today));

        // Everybody no group counts: a job category nobody has given a group,
        // and the people with no category. How many, and nothing else — they
        // are in no figure, so there is no Saudi count to give for them.
        $uncounted = $counts
            ->reject(fn (array $count, string $key) => $key !== '' && $claimed->has($key))
            ->sortBy(fn (array $count) => [-$count['people'], $count['job_category'] === null ? 1 : 0])
            ->map(fn (array $count) => ['job_category' => $count['job_category'], 'people' => $count['people']])
            ->values();

        // The company's figures are the groups' added up, and only theirs.
        $people = (int) $rows->sum('people');
        $saudis = (int) $rows->sum('saudis');

        return [
            'groups' => $rows,
            'overall' => [
                'people' => $people,
                'saudis' => $saudis,
                'unknown' => (int) $rows->sum('unknown'),
                'share' => self::share($saudis, $people),
                'short_by' => (int) $rows->sum('short_by'),
            ],
            'uncounted' => [
                'people' => (int) $uncounted->sum('people'),
                'categories' => $uncounted->all(),
            ],
            // Strictly: a group with nobody in it is null, and null == false.
            'compliant' => $rows->whereStrict('compliant', true)->count(),
            'not_compliant' => $rows->whereStrict('compliant', false)->count(),
            'empty' => $rows->whereStrict('compliant', null)->count(),
        ];
    }

    /**
     * Does this head count meet this percentage?
     *
     * Compared as whole numbers, so 9 of 13 against 70% is decided by
     * 900 < 910 and not by how 69.23 happened to be rounded.
     */
    public static function meets(int $saudis, int $people, float $percent): bool
    {
        return $people > 0 && $saudis * 100 >= $percent * $people - 1e-9;
    }

    /**
     * How many more Saudis the group needs, **at the size it is today**: the
     * Saudis the percentage requires of this many people, less the Saudis it
     * has. Marketing at 46 of 88 against 60% needs 53, so it is 7 short.
     *
     * Not "how many to hire". The first version answered that — each Saudi
     * hired also grows the group, so it said 17 for the same figures — and HR
     * read the number the way everybody does: 60% of 88, less 46. The page
     * cannot know whether a gap will be closed by hiring or by replacing, and
     * the requirement at today's head count is the one figure that is true
     * either way.
     */
    public static function shortBy(int $saudis, int $people, float $percent): int
    {
        return max(0, self::required($people, $percent) - $saudis);
    }

    /**
     * The Saudis this percentage requires of this many people, rounded up:
     * 52.8 people is 53. The same line meets() draws, so a group is short by
     * nought exactly when it is compliant.
     */
    public static function required(int $people, float $percent): int
    {
        return (int) ceil($percent * $people / 100 - 1e-9);
    }

    public static function share(int $saudis, int $people): ?float
    {
        return $people > 0 ? $saudis / $people * 100 : null;
    }

    /** @return array<string,mixed> */
    private function row(SaudizationGroup $group, Collection $counts, CarbonImmutable $today): array
    {
        $count = $group->job_category === null
            ? null
            : $counts->get(mb_strtolower($group->job_category));
        $people = (int) ($count['people'] ?? 0);
        $saudis = (int) ($count['saudis'] ?? 0);
        $required = (float) $group->required_percent;

        return [
            'group' => $group,
            'people' => $people,
            'saudis' => $saudis,
            'unknown' => (int) ($count['unknown'] ?? 0),
            'share' => self::share($saudis, $people),
            'required' => $required,
            // Null, not false, for a group with nobody in it: there is nothing
            // to be compliant or not about.
            'compliant' => $people > 0 ? self::meets($saudis, $people, $required) : null,
            'short_by' => self::shortBy($saudis, $people, $required),
            'saudis_required' => self::required($people, $required),
            'announced' => array_map(fn (array $step) => $step + [
                'meets' => $people > 0 ? self::meets($saudis, $people, $step['percent']) : null,
                'short_by' => self::shortBy($saudis, $people, $step['percent']),
                // The month has arrived: the "current" percentage may be out of date.
                'arrived' => $step['from'] !== null && $step['from']->toDateString() <= $today->toDateString(),
            ], $group->announced()),
        ];
    }

    /**
     * Head counts per Oracle job category, keyed by the category in lower
     * case ('' for no category).
     *
     * @return Collection<string, array{job_category: ?string, people:int, saudis:int, unknown:int}>
     */
    private function counts(): Collection
    {
        return Employee::query()
            ->whereNull('linked_primary_employee_id')
            ->where('status', '!=', 'terminated')
            // Oracle's list: the nationality and the category both come from it.
            ->whereNotNull('oracle_assignment_status')
            ->toBase()
            ->select('oracle_job_category')
            ->selectRaw('COUNT(*) as people')
            ->selectRaw('SUM(CASE WHEN LOWER(oracle_nationality) = ? THEN 1 ELSE 0 END) as saudis', [mb_strtolower(self::SAUDI)])
            ->selectRaw('SUM(CASE WHEN oracle_nationality IS NULL THEN 1 ELSE 0 END) as unknown')
            ->groupBy('oracle_job_category')
            ->get()
            ->mapWithKeys(fn ($row) => [mb_strtolower((string) $row->oracle_job_category) => [
                'job_category' => $row->oracle_job_category,
                'people' => (int) $row->people,
                'saudis' => (int) $row->saudis,
                'unknown' => (int) $row->unknown,
            ]]);
    }
}
