<?php

namespace App\Services\People;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\SaudizationGroup;
use Illuminate\Support\Collection;

/**
 * Saudization department by department: each of Oracle's departments, its
 * branches read as one ({@see DepartmentName}), against the percentage its own
 * mix of professions requires.
 *
 * **The ministry sets a percentage for a profession, not for a department**, so
 * a department has no percentage of its own. What it has is the people in it:
 * six accountants who must be 40% Saudi and two administrative staff who must
 * be 100%. Its target is what those professions ask of that many people —
 * 40% of 6 plus 100% of 2 is 4.4, so five Saudis — and the percentage shown
 * beside it is only that figure over the head count. A department with one
 * profession in it gets exactly the sum HR does by hand: the percentage of its
 * people, rounded up, less the Saudis it has.
 *
 * Two things follow, and the page says both:
 *
 *  - **Only people in a professional group are counted — the rest are not
 *    there at all.** A driver or a warehouse worker has no group in HR's table
 *    and so no percentage to be held to: not a Saudi, not a non-Saudi, nought
 *    ({@see Saudization}). They are in no row's people, no branch count and no
 *    total, and a department with nobody in a group is not a row. The page
 *    says in one line how many people that is and which departments it left
 *    out, so nobody has to wonder where they went.
 *  - **Saudis in one profession cover another inside the same department.**
 *    The figure is the department's fair share of the whole, not a ruling per
 *    profession; the professions inside it are listed under the row with each
 *    one's own share, so a department that is over on sales and under on
 *    engineers shows as exactly that.
 *
 * Same people as {@see Saudization}: current staff, one row per person, in
 * Oracle's list. Same head count, same caveat — the ministry weighs people.
 */
class SaudizationByDepartment
{
    public const SORTS = [
        'needs' => 'Most Saudis needed first',
        'people' => 'Largest first',
        'name' => 'By name',
    ];

    /**
     * @return array{
     *     departments: Collection<int, array<string,mixed>>,
     *     compliant: int, not_compliant: int,
     *     people: int, saudis: int, short_by: int,
     *     uncounted_people: int, unlisted: list<array{name: string, people: int}>
     * }
     */
    public function report(string $sort = 'needs'): array
    {
        $groups = SaudizationGroup::query()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (SaudizationGroup $group) => $group->job_category !== null)
            ->keyBy(fn (SaudizationGroup $group) => mb_strtolower($group->job_category));
        $branches = Branch::query()->pluck('name', 'id');

        $departments = [];

        foreach ($this->counts() as $count) {
            $key = DepartmentName::key($count->oracle_department);
            $people = (int) $count->people;

            $department = &$departments[$key];
            $department ??= ['key' => $key, 'forms' => [], 'every_form' => [], 'oracle_departments' => [],
                'branches' => [], 'groups' => [], 'uncounted' => 0];

            $form = DepartmentName::base($count->oracle_department);
            // Only to name a department that ends up with nobody counted.
            $department['every_form'][$form] = ($department['every_form'][$form] ?? 0) + $people;

            $group = $groups->get(mb_strtolower((string) $count->oracle_job_category));

            if (! $group) {
                // In no professional group: in no figure. Only how many.
                $department['uncounted'] += $people;
                unset($department);

                continue;
            }

            // Every spelling behind the row, and where its counted people are.
            $department['forms'][$form] = ($department['forms'][$form] ?? 0) + $people;
            $original = trim((string) $count->oracle_department);
            $department['oracle_departments'][$original] = ($department['oracle_departments'][$original] ?? 0) + $people;
            $branch = $branches[$count->branch_id] ?? 'No branch';
            $department['branches'][$branch] = ($department['branches'][$branch] ?? 0) + $people;

            $department['groups'][$group->id] ??= ['group' => $group, 'people' => 0, 'saudis' => 0];
            $department['groups'][$group->id]['people'] += $people;
            $department['groups'][$group->id]['saudis'] += (int) $count->saudis;

            unset($department);
        }

        $departments = collect($departments);
        // A department with nobody in a professional group is not a row.
        $rows = $departments->filter(fn (array $department) => $department['groups'] !== [])
            ->map(fn (array $department) => $this->row($department))->values();

        return [
            'departments' => $this->sorted($rows, $sort),
            'compliant' => $rows->where('compliant', true)->count(),
            'not_compliant' => $rows->where('compliant', false)->count(),
            'people' => (int) $rows->sum('people'),
            'saudis' => (int) $rows->sum('saudis'),
            'short_by' => (int) $rows->sum('short_by'),
            'uncounted_people' => (int) $departments->sum('uncounted'),
            'unlisted' => $departments->filter(fn (array $department) => $department['groups'] === [])
                ->map(fn (array $department) => ['name' => self::name($department['every_form']), 'people' => $department['uncounted']])
                ->sortBy(fn (array $department) => mb_strtolower($department['name']))
                ->values()->all(),
        ];
    }

    /**
     * The spelling most of a department's people are under; the shorter on a tie.
     *
     * @param  array<string,int>  $forms  base name => head count
     */
    private static function name(array $forms): string
    {
        $forms = collect($forms)->filter(fn ($count, $form) => (string) $form !== '');

        return $forms->keys()->map(fn ($form) => (string) $form)
            ->sortBy(fn (string $form) => [-$forms[$form], mb_strlen($form), $form])->first() ?? 'No department in Oracle';
    }

    /**
     * @param  array<string,mixed>  $department
     * @return array<string,mixed>
     */
    private function row(array $department): array
    {
        $groups = collect($department['groups'])
            ->map(fn (array $slice) => $slice + [
                'share' => Saudization::share($slice['saudis'], $slice['people']),
                'required' => (float) $slice['group']->required_percent,
                'meets' => Saudization::meets($slice['saudis'], $slice['people'], (float) $slice['group']->required_percent),
                // This profession's part of the department's target, unrounded:
                // 40% of 6 is 2.4. Rounding happens once, on the department.
                'asks' => (float) $slice['group']->required_percent * $slice['people'] / 100,
            ])
            ->sortByDesc('people')
            ->values();

        $people = (int) $groups->sum('people');
        $saudis = (int) $groups->sum('saudis');
        $asks = (float) $groups->sum('asks');
        // Rounded up once, like a group: 4.4 Saudis is five.
        $required = (int) ceil($asks - 1e-9);

        arsort($department['branches']);
        ksort($department['oracle_departments']);

        return [
            'key' => $department['key'],
            'name' => self::name($department['forms']),
            'people' => $people,
            'saudis' => $saudis,
            'share' => Saudization::share($saudis, $people),
            'target_percent' => $asks / $people * 100,
            'saudis_required' => $required,
            'compliant' => $saudis >= $required,
            'short_by' => max(0, $required - $saudis),
            'groups' => $groups,
            'branches' => $department['branches'],
            'oracle_departments' => $department['oracle_departments'],
        ];
    }

    private function sorted(Collection $rows, string $sort): Collection
    {
        $sorted = match ($sort) {
            'name' => $rows->sortBy(fn (array $row) => mb_strtolower($row['name'])),
            'people' => $rows->sortBy(fn (array $row) => [-$row['people'], mb_strtolower($row['name'])]),
            default => $rows->sortBy(fn (array $row) => [-$row['short_by'], -$row['people'], mb_strtolower($row['name'])]),
        };

        return $sorted->values();
    }

    /** Head counts per Oracle department, branch and job category. */
    private function counts(): Collection
    {
        return Employee::query()
            ->whereNull('linked_primary_employee_id')
            ->where('status', '!=', 'terminated')
            ->whereNotNull('oracle_assignment_status')
            ->toBase()
            ->select('oracle_department', 'branch_id', 'oracle_job_category')
            ->selectRaw('COUNT(*) as people')
            ->selectRaw('SUM(CASE WHEN LOWER(oracle_nationality) = ? THEN 1 ELSE 0 END) as saudis', [mb_strtolower(Saudization::SAUDI)])
            ->groupBy('oracle_department', 'branch_id', 'oracle_job_category')
            ->get();
    }
}
