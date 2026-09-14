<?php

namespace App\Services\Vacation;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Vacation\VacationEmployee;
use Illuminate\Support\Collection;

/**
 * Decides which NOC employee an Oracle person number in a book is.
 *
 *   1. candidates = employees whose oracle_emp_no is the number (linked
 *      secondary mailboxes excluded — they mirror a primary record);
 *   2. keep the ones in the book's branches, and any with no branch: the SSS
 *      Egypt and SamirGroup series collide, and a number held only by someone
 *      in another book's branch is somebody else;
 *   3. exactly one left → link it; several → ambiguous; none → no match;
 *   4. anything unsettled waits for HR on the person's page.
 *
 * Every import re-decides the rows the rule owns, so a corrected Oracle number
 * on an employee is followed. Never HrLookup::employee(): it quietly prefers
 * the active record, which would hand one person's leave to another across
 * the colliding series. A manual link is HR's decision and is never touched.
 */
class VacationLinker
{
    /** @var array<string, list<int>> book => its branch ids */
    private array $branchIds = [];

    /**
     * @param  iterable<VacationEmployee>  $people
     * @return int rows whose employee changed
     */
    public function linkAll(iterable $people): int
    {
        $people = collect($people)->reject(fn (VacationEmployee $person) => $person->isManual())->values();

        if ($people->isEmpty()) {
            return 0;
        }

        $holders = $this->holders($people->flatMap(fn (VacationEmployee $person) => $this->lookupNumbers($person->oracle_emp_no))->unique()->values()->all());
        $changed = 0;

        foreach ($people as $person) {
            [$employeeId, $method, $candidateIds] = $this->decide($person, $holders);

            if ($person->employee_id !== $employeeId) {
                $changed++;
            }

            $person->forceFill([
                'employee_id' => $employeeId,
                'match_method' => $method,
                'candidate_ids' => $candidateIds ?: null,
            ]);

            if ($person->isDirty()) {
                $person->save();
            }
        }

        return $changed;
    }

    /** HR's link, or with no employee: "this number is nobody in the NOC". */
    public function linkManually(VacationEmployee $person, ?Employee $employee, ?int $userId): void
    {
        // A linked secondary mailbox is the same person as the primary it mirrors.
        if ($employee?->linked_primary_employee_id) {
            $employee = $employee->linkedPrimary ?? $employee;
        }

        $person->forceFill([
            'employee_id' => $employee?->id,
            'match_method' => VacationEmployee::METHOD_MANUAL,
            'candidate_ids' => null,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ])->save();
    }

    /** Drops HR's decision and lets the rule decide again. */
    public function resetToAuto(VacationEmployee $person): void
    {
        $person->forceFill(['match_method' => null, 'confirmed_by' => null, 'confirmed_at' => null]);

        $this->linkAll([$person]);
    }

    /**
     * @return array{0: ?int, 1: string, 2: list<int>} employee id, match method, candidate ids
     */
    public function resolve(VacationEmployee $person): array
    {
        return $this->decide($person, $this->holders($this->lookupNumbers($person->oracle_emp_no)));
    }

    /** @return list<string> the forms the number is looked up as */
    public function lookupNumbers(string $number): array
    {
        $number = trim($number);

        return array_values(array_unique(array_filter([$number, ltrim($number, '0')], fn ($form) => $form !== '')));
    }

    /**
     * The branch names the book lists that no branch is called any more. While
     * one is missing, that branch's people cannot be linked automatically.
     *
     * @return list<string>
     */
    public function missingBranches(string $book): array
    {
        $names = VacationEmployee::books()[$book]['branches'] ?? [];

        return array_values(array_diff($names, Branch::query()->whereIn('name', $names ?: ['-'])->pluck('name')->all()));
    }

    /**
     * @param  Collection<string, Collection<int, Employee>>  $holders  by oracle_emp_no
     * @return array{0: ?int, 1: string, 2: list<int>}
     */
    private function decide(VacationEmployee $person, Collection $holders): array
    {
        $candidates = collect($this->lookupNumbers($person->oracle_emp_no))
            ->flatMap(fn (string $number) => $holders->get($number, collect()))
            ->unique('id')
            ->values();

        if ($candidates->isEmpty()) {
            return [null, VacationEmployee::METHOD_NONE, []];
        }

        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $bookBranches = $this->branchIdsOf($person->book);

        $pool = $bookBranches === null
            ? $candidates
            : $candidates->filter(fn (Employee $e) => $e->branch_id === null || in_array((int) $e->branch_id, $bookBranches, true))->values();

        if ($pool->count() === 1) {
            return $candidates->count() === 1
                ? [(int) $pool->first()->id, VacationEmployee::METHOD_AUTO, []]
                : [(int) $pool->first()->id, VacationEmployee::METHOD_AUTO_BRANCH, $candidateIds];
        }

        return [null, $pool->isEmpty() ? VacationEmployee::METHOD_NONE : VacationEmployee::METHOD_AMBIGUOUS, $candidateIds];
    }

    /**
     * @param  list<string>  $numbers
     * @return Collection<string, Collection<int, Employee>>
     */
    private function holders(array $numbers): Collection
    {
        return collect($numbers)
            ->chunk(500)
            ->flatMap(fn (Collection $chunk) => Employee::query()
                ->whereIn('oracle_emp_no', $chunk->values()->all())
                ->whereNull('linked_primary_employee_id')
                ->get(['id', 'oracle_emp_no', 'branch_id']))
            ->unique('id')
            ->groupBy(fn (Employee $e) => trim((string) $e->oracle_emp_no));
    }

    /**
     * The book's branch ids; null when it names none — or none that exist, so
     * a stale config degrades to "any holder" instead of linking nobody.
     *
     * @return list<int>|null
     */
    private function branchIdsOf(string $book): ?array
    {
        if (! array_key_exists($book, $this->branchIds)) {
            $names = VacationEmployee::books()[$book]['branches'] ?? [];

            $this->branchIds[$book] = $names === []
                ? []
                : Branch::query()->whereIn('name', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->branchIds[$book] === [] ? null : $this->branchIds[$book];
    }
}
