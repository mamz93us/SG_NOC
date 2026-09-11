<?php

namespace App\Services\Attendance;

use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\Employee;

/**
 * Links a BioTime emp_code to a NOC employee.
 *
 * The punch query carries no name or email, so the two-signal matcher in
 * OracleHrImportService has nothing to compare. The rule instead assumes the
 * BioTime emp_code IS the Oracle EMP_NO:
 *
 *   1. candidates = employees whose oracle_emp_no is the code (linked
 *      secondary mailboxes excluded — they mirror a primary record);
 *   2. exactly one → link it;
 *   3. several — the SSS Egypt and SamirGroup series collide — → keep the one
 *      in the branch of the areas the code punched in, or failing that the
 *      source's default branch;
 *   4. otherwise leave it for HR on the Employee Mapping page.
 *
 * Never HrLookup::employee(): it quietly prefers the active record, which
 * would book one person's punches to another across the colliding series.
 * A manual link is HR's decision and is never overwritten here.
 */
class EmployeeLinker
{
    public function __construct(private AttendanceDayProcessor $processor) {}

    /** @return bool true when the linked employee changed */
    public function autoLink(BiotimeEmployee $biotimeEmployee): bool
    {
        if ($biotimeEmployee->isManual()) {
            return false;
        }

        [$employeeId, $method, $candidateIds] = $this->resolve($biotimeEmployee);
        $old = $biotimeEmployee->employee_id;

        $biotimeEmployee->forceFill([
            'employee_id' => $employeeId,
            'match_method' => $method,
            'candidate_ids' => $candidateIds ?: null,
        ])->save();

        if ($old !== $employeeId) {
            $this->processor->relink($biotimeEmployee, $old);

            return true;
        }

        return false;
    }

    /** HR's link, or with no employee: "this code is not a NOC employee". */
    public function linkManually(BiotimeEmployee $biotimeEmployee, ?Employee $employee, ?int $userId): void
    {
        $old = $biotimeEmployee->employee_id;

        $biotimeEmployee->forceFill([
            'employee_id' => $employee?->id,
            'match_method' => BiotimeEmployee::METHOD_MANUAL,
            'candidate_ids' => null,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ])->save();

        // Always rebuild: even with the same (empty) employee the day flags
        // change from "unmapped" to "not an employee".
        $this->processor->relink($biotimeEmployee, $old);
    }

    /** Drops HR's decision and lets the automatic rule decide again. */
    public function resetToAuto(BiotimeEmployee $biotimeEmployee): void
    {
        $old = $biotimeEmployee->employee_id;

        $biotimeEmployee->forceFill([
            'match_method' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ])->save();

        if (! $this->autoLink($biotimeEmployee)) {
            $this->processor->relink($biotimeEmployee, $old);
        }
    }

    /**
     * Retries every code that has no employee and no manual decision — new
     * employees may have been imported, or an area mapped to a branch.
     *
     * @return int codes newly linked
     */
    public function retryUnlinked(?BiotimeSource $source = null): int
    {
        $linked = 0;

        BiotimeEmployee::query()
            ->when($source, fn ($q) => $q->where('biotime_source_id', $source->id))
            ->whereNull('employee_id')
            ->where(fn ($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', BiotimeEmployee::METHOD_MANUAL))
            ->with('source')
            ->chunkById(200, function ($rows) use (&$linked) {
                foreach ($rows as $biotimeEmployee) {
                    if ($this->autoLink($biotimeEmployee)) {
                        $linked++;
                    }
                }
            });

        return $linked;
    }

    /**
     * @return array{0: ?int, 1: string, 2: list<int>} employee id, match method, candidate ids
     */
    public function resolve(BiotimeEmployee $biotimeEmployee): array
    {
        $code = trim((string) $biotimeEmployee->emp_code);
        $variants = array_values(array_unique(array_filter(
            [$code, ltrim($code, '0')],
            fn ($v) => $v !== ''
        )));

        if ($variants === []) {
            return [null, BiotimeEmployee::METHOD_NONE, []];
        }

        $candidates = Employee::query()
            ->whereIn('oracle_emp_no', $variants)
            ->whereNull('linked_primary_employee_id')
            ->get(['id', 'branch_id']);

        if ($candidates->isEmpty()) {
            return [null, BiotimeEmployee::METHOD_NONE, []];
        }

        if ($candidates->count() === 1) {
            return [(int) $candidates->first()->id, BiotimeEmployee::METHOD_AUTO, []];
        }

        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $inBranches = fn (array $branchIds) => $candidates
            ->filter(fn ($e) => $e->branch_id !== null && in_array((int) $e->branch_id, $branchIds, true))
            ->values();

        $byLocation = $inBranches($this->punchBranches($biotimeEmployee));
        if ($byLocation->count() === 1) {
            return [(int) $byLocation->first()->id, BiotimeEmployee::METHOD_AUTO_BRANCH, $candidateIds];
        }

        $default = $biotimeEmployee->source?->default_branch_id;
        if ($byLocation->isEmpty() && $default) {
            $byDefault = $inBranches([(int) $default]);
            if ($byDefault->count() === 1) {
                return [(int) $byDefault->first()->id, BiotimeEmployee::METHOD_AUTO_BRANCH, $candidateIds];
            }
        }

        return [null, BiotimeEmployee::METHOD_AMBIGUOUS, $candidateIds];
    }

    /**
     * Branches of the areas the code punched in — or, for access-control
     * sources, which have no areas, of the terminals it punched on.
     *
     * @return list<int>
     */
    private function punchBranches(BiotimeEmployee $biotimeEmployee): array
    {
        $ids = [];

        if ($areas = $biotimeEmployee->areas ?: []) {
            $ids = BiotimeArea::where('biotime_source_id', $biotimeEmployee->biotime_source_id)
                ->whereIn('area_alias', $areas)
                ->whereNotNull('branch_id')
                ->pluck('branch_id')
                ->all();
        }

        if ($terminals = $biotimeEmployee->terminals ?: []) {
            $ids = array_merge($ids, BiotimeTerminal::where('biotime_source_id', $biotimeEmployee->biotime_source_id)
                ->whereIn('terminal_sn', $terminals)
                ->whereNotNull('branch_id')
                ->pluck('branch_id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
