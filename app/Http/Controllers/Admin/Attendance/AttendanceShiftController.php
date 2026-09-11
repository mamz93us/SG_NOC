<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceTask;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Attendance → Shifts: the shifts themselves, and who works which.
 *
 * A change queues a recalculation of the recent days for the people it
 * covers (AttendanceTask, run by `attendance:work`). Doing it inline — 14
 * days for everyone — held a PHP-FPM worker past nginx's timeout: a 504.
 */
class AttendanceShiftController extends Controller
{
    /**
     * A change recalculates this many days back. Older days follow with
     * `php artisan attendance:process --from=…`.
     */
    public const REBUILD_DAYS = 14;

    public function index(Request $request): View
    {
        $assignments = AttendanceShiftAssignment::with('shift:id,name,start_time,end_time')
            ->orderByRaw("CASE scope_type WHEN 'all' THEN 1 WHEN 'branch' THEN 2 WHEN 'department' THEN 3 ELSE 4 END")
            ->orderByDesc('effective_from')
            ->get();

        return view('admin.attendance.shifts.index', [
            'shifts' => AttendanceShift::withCount('assignments')->orderBy('start_time')->get(),
            'assignments' => $assignments,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'assignedEmployees' => Employee::whereIn('id', $assignments->where('scope_type', 'employee')->pluck('scope_id'))
                ->get(['id', 'name', 'oracle_emp_no'])->keyBy('id'),
            'employeeOptions' => $request->user()?->can('manage-attendance')
                ? Employee::with('branch:id,name')->where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name', 'oracle_emp_no', 'branch_id'])
                : collect(),
            'rebuildDays' => self::REBUILD_DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = AttendanceShift::create($this->validated($request));
        $this->log('AttendanceShift', $shift->id, 'created', $shift->only($shift->getFillable()));

        return back()->with('success', "Shift \"{$shift->name}\" created. Assign it below to put it to use.");
    }

    public function update(Request $request, AttendanceShift $shift): RedirectResponse
    {
        $old = $shift->only($shift->getFillable());
        $shift->update($this->validated($request));
        $this->log('AttendanceShift', $shift->id, 'updated', ['old' => $old, 'new' => $shift->only($shift->getFillable())]);

        $queued = $this->queueRebuild($shift->assignments()->get(), "shift \"{$shift->name}\" changed");

        return back()->with('success', "Shift \"{$shift->name}\" saved.".$this->queuedNote($queued));
    }

    public function destroy(AttendanceShift $shift): RedirectResponse
    {
        if ($shift->assignments()->exists()) {
            return back()->with('error', "\"{$shift->name}\" is still assigned — remove its assignments first, or switch it off.");
        }

        $shift->delete();
        $this->log('AttendanceShift', $shift->id, 'deleted', ['name' => $shift->name]);

        return back()->with('success', "Shift \"{$shift->name}\" deleted.");
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attendance_shift_id' => 'required|integer|exists:attendance_shifts,id',
            'scope_type' => 'required|in:all,branch,department,employee',
            'branch_id' => 'nullable|required_if:scope_type,branch|integer|exists:branches,id',
            'department_id' => 'nullable|required_if:scope_type,department|integer|exists:departments,id',
            'employee' => 'nullable|required_if:scope_type,employee|string|max:255',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $scopeId = match ($data['scope_type']) {
            'branch' => (int) $data['branch_id'],
            'department' => (int) $data['department_id'],
            'employee' => $this->employeeIdFrom((string) ($data['employee'] ?? '')),
            default => null,
        };

        if ($data['scope_type'] === 'employee' && ! $scopeId) {
            return back()->withInput()->with('error', 'Pick the employee from the list — start typing a name or Oracle number.');
        }

        $assignment = AttendanceShiftAssignment::create([
            'attendance_shift_id' => $data['attendance_shift_id'],
            'scope_type' => $data['scope_type'],
            'scope_id' => $scopeId,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'created_by' => Auth::id(),
        ]);
        $this->log('AttendanceShiftAssignment', $assignment->id, 'created', $assignment->only($assignment->getFillable()));

        $queued = $this->queueRebuild(collect([$assignment]), 'shift assigned');

        return back()->with('success', 'Shift assigned.'.$this->queuedNote($queued));
    }

    public function unassign(AttendanceShiftAssignment $assignment): RedirectResponse
    {
        $removed = clone $assignment;
        $assignment->delete();
        $this->log('AttendanceShiftAssignment', $removed->id, 'deleted', $removed->only($removed->getFillable()));

        $queued = $this->queueRebuild(collect([$removed]), 'shift assignment removed');

        return back()->with('success', 'Assignment removed.'.$this->queuedNote($queued));
    }

    /** Queues a recalculation of the recent days of everyone the assignments cover. */
    private function queueRebuild(Collection $assignments, string $why): int
    {
        $today = CarbonImmutable::today();
        $floor = $today->subDays(self::REBUILD_DAYS - 1);
        $queued = 0;

        foreach ($assignments as $assignment) {
            $from = CarbonImmutable::parse($assignment->effective_from->toDateString());
            $from = $from->greaterThan($floor) ? $from : $floor;
            $to = $assignment->effective_to && $assignment->effective_to->lessThan($today)
                ? CarbonImmutable::parse($assignment->effective_to->toDateString())
                : $today;

            if ($from->greaterThan($to)) {
                continue;
            }

            AttendanceTask::queue('rebuild', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employee_ids' => $this->employeeIds($assignment),
            ], "Recalculate {$from->format('d M')} – {$to->format('d M')} ({$why})", Auth::id());
            $queued++;
        }

        return $queued;
    }

    /** @return list<int>|null null = everyone */
    private function employeeIds(AttendanceShiftAssignment $assignment): ?array
    {
        return match ($assignment->scope_type) {
            'branch' => Employee::where('branch_id', $assignment->scope_id)->pluck('id')->all(),
            'department' => Employee::where('department_id', $assignment->scope_id)->pluck('id')->all(),
            'employee' => [(int) $assignment->scope_id],
            default => null,
        };
    }

    private function queuedNote(int $queued): string
    {
        return $queued
            ? ' The last '.self::REBUILD_DAYS.' days are being recalculated in the background — the banner above shows when it is done.'
            : '';
    }

    /** The picker's value starts with the employee id: "123 · Name · Oracle 456 · Branch". */
    private function employeeIdFrom(string $value): ?int
    {
        if (! preg_match('/^\s*#?(\d+)/', $value, $m)) {
            return null;
        }

        return Employee::whereKey((int) $m[1])->exists() ? (int) $m[1] : null;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'grace_in_minutes' => 'required|integer|min:0|max:240',
            'grace_out_minutes' => 'required|integer|min:0|max:240',
            'max_hours' => 'nullable|integer|min:1|max:24',
            'min_overtime_minutes' => 'required|integer|min:0|max:600',
            'off_days' => 'nullable|array',
            'off_days.*' => 'integer|between:1,7',
        ]);

        $data['off_days'] = array_values(array_unique(array_map('intval', $data['off_days'] ?? [])));
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function log(string $type, int $id, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => $type,
            'model_id' => $id,
            'action' => $action,
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
