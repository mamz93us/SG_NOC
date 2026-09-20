<?php

namespace App\Services\OraclePortal;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use Illuminate\Support\Collection;

/**
 * The two lists the HR Import page shows about people leaving — and they are
 * different in kind, which is the point of keeping them apart.
 *
 * **Suggested leavers** are people Oracle explicitly calls INACTIVE while the
 * NOC still has them employed. That is a statement, so it is actionable: an
 * admin terminates or ignores each one. Nothing here writes `status`.
 *
 * **Absent from the feed** are people the NOC holds an Oracle number for whom
 * Oracle did not list at all. This is reported and never actionable, because
 * the feed is the SamirGroup Saudi book and being missing from it is the normal
 * condition for an SSS Egypt employee. Grouping by branch is what makes that
 * legible instead of alarming: a Cairo group is labelled as out of book rather
 * than presented as thirty-eight people who have left.
 */
class LeaverReview
{
    public function __construct(private PortalBook $book) {}

    /**
     * People Oracle calls inactive who are still employed here.
     *
     * @return Collection<int, Employee>
     */
    public function leavers(): Collection
    {
        return Employee::query()
            ->with('branch:id,name')
            ->whereNull('linked_primary_employee_id')
            ->where('oracle_assignment_status', 'INACTIVE')
            ->where('status', '!=', 'terminated')
            ->whereNull('oracle_leaver_ignored_at')
            ->whereIn('branch_id', $this->bookBranchIds())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'oracle_emp_no', 'branch_id', 'job_title',
                'status', 'oracle_employee_category', 'oracle_person_type']);
    }

    /** People an admin has already decided Oracle is wrong about. */
    public function ignored(): Collection
    {
        return Employee::query()
            ->with('branch:id,name')
            ->whereNull('linked_primary_employee_id')
            ->where('oracle_assignment_status', 'INACTIVE')
            ->where('status', '!=', 'terminated')
            ->whereNotNull('oracle_leaver_ignored_at')
            ->orderBy('name')
            ->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'oracle_leaver_ignored_at']);
    }

    /**
     * Employees holding an Oracle number that the latest pull did not list,
     * grouped by branch with a note on each group.
     *
     * Returns an empty list when no API batch exists yet — with nothing to
     * compare against, everyone would look absent.
     *
     * @return array{as_of: ?\Illuminate\Support\Carbon, groups: list<array{
     *     branch: string, in_book: bool, note: string, employees: Collection<int, Employee>}>}
     */
    public function absent(): array
    {
        $batch = HrImportBatch::query()->where('source', 'api')->latest('id')->first();

        if (! $batch) {
            return ['as_of' => null, 'groups' => []];
        }

        $listed = HrImportRow::query()
            ->where('hr_import_batch_id', $batch->id)
            ->whereNotNull('emp_no')
            ->pluck('emp_no')
            ->mapWithKeys(fn ($no) => [ltrim(trim((string) $no), '0') => true])
            ->all();

        $employees = Employee::query()
            ->with('branch:id,name')
            ->whereNull('linked_primary_employee_id')
            ->whereNotNull('oracle_emp_no')
            ->where('oracle_emp_no', '<>', '')
            ->where('status', '!=', 'terminated')
            ->orderBy('name')
            ->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'job_title', 'status', 'employee_type'])
            ->reject(fn (Employee $e) => isset($listed[ltrim(trim((string) $e->oracle_emp_no), '0')]));

        $bookIds = $this->bookBranchIds();
        $groups = [];

        foreach ($employees->groupBy(fn (Employee $e) => $e->branch?->name ?? 'No branch') as $branch => $people) {
            $branchId = $people->first()->branch_id;
            $inBook = $branchId !== null && in_array((int) $branchId, $bookIds, true);

            $groups[] = [
                'branch' => (string) $branch,
                'in_book' => $inBook,
                'note' => $inBook
                    ? 'In this feed\'s branches but not listed by Oracle. Usually somebody Oracle has not '
                        .'onboarded yet, or whose record is under a different number — not evidence they have left.'
                    : 'Outside this feed. The Employee Portal serves the SamirGroup Saudi book only, so these '
                        .'people are always absent from it and this says nothing about their employment.',
                'employees' => $people,
            ];
        }

        // In-book groups first: those are the only ones worth a second look.
        usort($groups, fn ($a, $b) => [$b['in_book'], $a['branch']] <=> [$a['in_book'], $b['branch']]);

        return ['as_of' => $batch->created_at, 'groups' => $groups];
    }

    /**
     * @return list<int>
     */
    private function bookBranchIds(): array
    {
        try {
            return $this->book->branchIds();
        } catch (\Throwable) {
            // A misconfigured book must not take the whole review page down.
            // The lists simply come back empty, and the sync itself refuses
            // loudly for the same reason.
            return Branch::query()->whereRaw('1 = 0')->pluck('id')->all();
        }
    }
}
