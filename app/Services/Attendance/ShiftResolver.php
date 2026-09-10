<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceHoliday;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Which shift applies to a person on a date, whether the date is a holiday for
 * them, and whether they are expected to punch at all.
 *
 * A shift is assigned to everyone, a branch, a department or one employee,
 * from a date (optionally to a date). The most specific assignment wins —
 * employee over department over branch over everyone — then the most recent.
 *
 * Everything is loaded once and kept in memory: a rebuild asks this thousands
 * of times. Call forget() after shifts, assignments or holidays change.
 */
class ShiftResolver
{
    public const PRIORITY = ['employee' => 4, 'department' => 3, 'branch' => 2, 'all' => 1];

    private ?Collection $assignments = null;

    private ?Collection $holidays = null;

    /** @var array<int, true>|null */
    private ?array $linked = null;

    /** @var array<int, string>|null */
    private ?array $codes = null;

    private ?bool $nightShifts = null;

    /** @var array<int, ?Employee> */
    private array $employees = [];

    /** @var array<int, ShiftRule> */
    private array $rules = [];

    private const EMPLOYEE_COLUMNS = ['id', 'branch_id', 'department_id', 'status', 'hired_date', 'terminated_date'];

    public function forEmployee(int $employeeId, string $date): ?ShiftRule
    {
        $employee = $this->employee($employeeId);
        if (! $employee) {
            return null;
        }

        $best = null;
        $bestRank = null;

        foreach ($this->assignments() as $assignment) {
            if ($assignment->effective_from->toDateString() > $date) {
                continue;
            }
            if ($assignment->effective_to && $assignment->effective_to->toDateString() < $date) {
                continue;
            }

            $scopeId = (int) $assignment->scope_id;
            $applies = match ($assignment->scope_type) {
                'employee' => $scopeId === $employeeId,
                'department' => $employee->department_id !== null && $scopeId === (int) $employee->department_id,
                'branch' => $employee->branch_id !== null && $scopeId === (int) $employee->branch_id,
                'all' => true,
                default => false,
            };

            if (! $applies) {
                continue;
            }

            $rank = [self::PRIORITY[$assignment->scope_type], $assignment->effective_from->toDateString(), $assignment->id];
            if ($bestRank === null || $rank > $bestRank) {
                $best = $assignment;
                $bestRank = $rank;
            }
        }

        return $best ? ($this->rules[$best->attendance_shift_id] ??= $best->shift->toRule()) : null;
    }

    /** The holiday's name, when $date is one for this branch (or for every branch). */
    public function holidayFor(?int $branchId, string $date): ?string
    {
        $this->holidays ??= AttendanceHoliday::query()
            ->get(['id', 'holiday_date', 'name', 'branch_id'])
            ->groupBy(fn (AttendanceHoliday $h) => $h->holiday_date->toDateString());

        foreach ($this->holidays->get($date, []) as $holiday) {
            if ($holiday->branch_id === null || ($branchId !== null && (int) $holiday->branch_id === $branchId)) {
                return $holiday->name;
            }
        }

        return null;
    }

    /**
     * Only people who punch on BioTime and were employed that day can be
     * absent — someone with no BioTime code would be "absent" every day.
     */
    public function expectedToWork(int $employeeId, string $date): bool
    {
        $employee = $this->employee($employeeId);

        if (! $employee || ! isset($this->linked()[$employeeId]) || $employee->status === 'on_leave') {
            return false;
        }

        if ($employee->hired_date && $date < $employee->hired_date->toDateString()) {
            return false;
        }

        if ($employee->terminated_date) {
            return $date <= $employee->terminated_date->toDateString();
        }

        return $employee->status !== 'terminated';
    }

    public function employee(int $employeeId): ?Employee
    {
        if (! array_key_exists($employeeId, $this->employees)) {
            $this->employees[$employeeId] = Employee::query()->whereKey($employeeId)->first(self::EMPLOYEE_COLUMNS);
        }

        return $this->employees[$employeeId];
    }

    /** @param  list<int>  $employeeIds */
    public function preloadEmployees(array $employeeIds): void
    {
        $missing = array_values(array_diff($employeeIds, array_keys($this->employees)));

        foreach (array_chunk($missing, 1000) as $chunk) {
            $found = Employee::query()->whereIn('id', $chunk)->get(self::EMPLOYEE_COLUMNS)->keyBy('id');
            foreach ($chunk as $id) {
                $this->employees[$id] = $found->get($id);
            }
        }
    }

    /** @return list<int> employees with at least one BioTime code */
    public function linkedEmployeeIds(): array
    {
        return array_keys($this->linked());
    }

    public function codesFor(int $employeeId): string
    {
        if ($this->codes === null) {
            $this->codes = BiotimeEmployee::query()
                ->whereNotNull('employee_id')
                ->get(['employee_id', 'emp_code'])
                ->groupBy('employee_id')
                ->map(fn ($rows) => $rows->pluck('emp_code')->unique()->implode(', '))
                ->all();
        }

        return $this->codes[$employeeId] ?? '';
    }

    public function hasNightShifts(): bool
    {
        return $this->nightShifts ??= AttendanceShift::query()
            ->where('is_active', true)
            ->where('crosses_midnight', true)
            ->exists();
    }

    public function forget(): void
    {
        $this->assignments = null;
        $this->holidays = null;
        $this->linked = null;
        $this->codes = null;
        $this->nightShifts = null;
        $this->employees = [];
        $this->rules = [];
    }

    private function assignments(): Collection
    {
        return $this->assignments ??= AttendanceShiftAssignment::query()
            ->with('shift')
            ->whereHas('shift', fn ($q) => $q->where('is_active', true))
            ->get();
    }

    /** @return array<int, true> */
    private function linked(): array
    {
        return $this->linked ??= BiotimeEmployee::query()
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
