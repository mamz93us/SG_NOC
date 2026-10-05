<?php

namespace App\Services\People;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How the workforce divides by something Oracle says about each person: the
 * job category, the profession on their official papers, or their nationality.
 *
 * **The parts always add up to the whole.** Somebody with nothing in the field
 * is counted in a named group, never left out, and there are two such groups
 * because they mean different things:
 *
 *  - NONE — Oracle lists the person and has nothing in this field for them
 *    (it sends "-" for 135 job categories and 10 professions; a nationality
 *    is missing for somebody the nationality export does not list).
 *  - OUTSIDE — Oracle's employee list does not cover the person at all. The
 *    Employee Portal serves the Saudi book, so every SSS Egypt employee lands
 *    here, as does anyone not yet matched to their Oracle record. Counting
 *    them as "no profession" would say something about them Oracle never did.
 *
 * "Oracle lists the person" is `oracle_assignment_status` being filled: the
 * employee sync writes it for everyone it reaches and for nobody else.
 *
 * One person is one row: a linked secondary mailbox is the same person as its
 * main record, which is where Oracle's facts are kept.
 *
 * A group's count and the list behind it come from the same query, compared
 * the same way by the database, so a row saying 22 always opens 22 people.
 */
class WorkforceBreakdown
{
    /** What the workforce can be divided by: key => label and employees column. */
    public const DIMENSIONS = [
        'job_category' => ['label' => 'Job category', 'plural' => 'job categories', 'column' => 'oracle_job_category'],
        'profession' => ['label' => 'Profession', 'plural' => 'professions', 'column' => 'oracle_profession'],
        // From Oracle's nationality export, not its API: see NationalityImporter.
        'nationality' => ['label' => 'Nationality', 'plural' => 'nationalities', 'column' => 'oracle_nationality'],
    ];

    /** Group keys that are not a value. No real value starts with a tilde. */
    public const NONE = '~none';

    public const OUTSIDE = '~outside';

    public const STATUS = [
        'active' => 'Current staff',
        'left' => 'Has left',
        'all' => 'Everyone',
    ];

    /**
     * Every group with its head count, largest first, the two unnamed groups last.
     *
     * @param  array{status?: string, branch?: ?int}  $filters
     * @return Collection<int, array{key: string, label: string, count: int, share: float, named: bool}>
     */
    public function groups(string $dimension, array $filters): Collection
    {
        $column = self::DIMENSIONS[$dimension]['column'];
        $label = self::DIMENSIONS[$dimension]['label'];

        $named = $this->people($filters)
            ->whereNotNull($column)
            ->toBase()
            ->select($column.' as label')
            ->selectRaw('COUNT(*) as total')
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => ['key' => (string) $row->label, 'label' => (string) $row->label, 'count' => (int) $row->total, 'named' => true])
            ->sort(fn ($a, $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']])
            ->values();

        $unnamed = collect([
            ['key' => self::NONE, 'label' => 'No '.mb_strtolower($label).' in Oracle',
                'count' => $this->pick($this->people($filters), $dimension, self::NONE)->count(), 'named' => false],
            ['key' => self::OUTSIDE, 'label' => 'Not in Oracle\'s employee list',
                'count' => $this->pick($this->people($filters), $dimension, self::OUTSIDE)->count(), 'named' => false],
        ])->where('count', '>', 0);

        $groups = $named->concat($unnamed)->values();
        $total = (int) $groups->sum('count');

        return $groups->map(fn (array $group) => $group + ['share' => $total > 0 ? $group['count'] / $total : 0.0]);
    }

    /**
     * The people in one group — or everybody, with no group picked.
     *
     * @param  array{status?: string, branch?: ?int}  $filters
     */
    public function employees(string $dimension, ?string $pick, array $filters, string $search = '', int $perPage = 50): LengthAwarePaginator
    {
        $search = trim($search);

        return $this->pick($this->people($filters), $dimension, $pick)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%")
                ->orWhere('oracle_emp_no', $search)))
            ->with(['branch:id,name', 'department:id,name'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Main records only, by employment status and branch.
     *
     * @param  array{status?: string, branch?: ?int}  $filters
     */
    private function people(array $filters): Builder
    {
        $status = $filters['status'] ?? 'active';

        return Employee::query()
            ->whereNull('linked_primary_employee_id')
            ->when($status === 'active', fn (Builder $q) => $q->where('status', '!=', 'terminated'))
            ->when($status === 'left', fn (Builder $q) => $q->where('status', 'terminated'))
            ->when($filters['branch'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id));
    }

    /** Narrow to one group. Both the count and the list go through here. */
    private function pick(Builder $query, string $dimension, ?string $pick): Builder
    {
        $column = self::DIMENSIONS[$dimension]['column'];

        return match (true) {
            $pick === null || $pick === '' => $query,
            $pick === self::NONE => $query->whereNull($column)->whereNotNull('oracle_assignment_status'),
            $pick === self::OUTSIDE => $query->whereNull($column)->whereNull('oracle_assignment_status'),
            default => $query->where($column, $pick),
        };
    }
}
