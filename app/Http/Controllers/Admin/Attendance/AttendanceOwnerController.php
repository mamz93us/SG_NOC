<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceOwner;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Attendance → Owners: who may ask the home-portal assistant about
 * attendance beyond their own direct reports — everyone in the company, or
 * everyone in chosen branches (a general manager, a branch GM).
 * AssistantToolbox::team() reads the list.
 *
 * Every change is also written as its own action (attendance_owner_added /
 * _changed / _removed), kept for the security retention window: this list
 * decides who can read other people's attendance.
 */
class AttendanceOwnerController extends Controller
{
    /** @var Collection<int, Branch>|null keyed by id */
    private ?Collection $branches = null;

    public function index(Request $request): View
    {
        $owners = AttendanceOwner::with(['employee.branch:id,name', 'creator:id,name'])
            ->get()
            ->sortBy(fn (AttendanceOwner $owner) => [$owner->isCompanyWide() ? 0 : 1, mb_strtolower((string) $owner->employee?->name)])
            ->values();

        return view('admin.attendance.owners.index', [
            'owners' => $owners,
            'branches' => $this->branches(),
            // Primary records only: a linked secondary mailbox is the same
            // person, and the toolbox finds the row from either.
            'employeeOptions' => $request->user()?->can('manage-attendance-owners')
                ? Employee::with('branch:id,name')
                    ->where('status', '!=', 'terminated')
                    ->whereNull('linked_primary_employee_id')
                    ->whereNotIn('id', $owners->pluck('employee_id')->all())
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'oracle_emp_no', 'branch_id'])
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, withEmployee: true);
        $employee = $this->employeeFrom((string) $data['employee']);

        if (! $employee) {
            return back()->withInput()->with('error', 'Pick the person from the list — start typing a name, email or Oracle number.');
        }

        if ($employee->status === 'terminated') {
            return back()->withInput()->with('error', "{$employee->name} has left the company, so they cannot be an attendance owner.");
        }

        if (AttendanceOwner::where('employee_id', $employee->id)->exists()) {
            return back()->withInput()->with('error', "{$employee->name} is already on the list — change their row below instead.");
        }

        $owner = AttendanceOwner::create($this->attributes($data) + [
            'employee_id' => $employee->id,
            'created_by' => Auth::id(),
        ]);

        $this->audit('attendance_owner_added', $owner, $employee, ['new' => $this->describe($owner)]);

        return back()->with('success', "{$employee->name} added. They can ask the assistant about the attendance of: {$owner->accessLabel($this->branches())}.");
    }

    public function update(Request $request, AttendanceOwner $owner): RedirectResponse
    {
        $data = $this->validated($request);
        $before = $this->describe($owner);

        $owner->update($this->attributes($data));
        $after = $this->describe($owner);

        if ($after !== $before) {
            $this->audit('attendance_owner_changed', $owner, $owner->employee, ['old' => $before, 'new' => $after]);
        }

        return back()->with('success', ($owner->employee?->name ?? 'Owner')." saved: {$after['access']}.");
    }

    public function destroy(AttendanceOwner $owner): RedirectResponse
    {
        $employee = $owner->employee;
        $before = $this->describe($owner);

        $owner->delete();
        $this->audit('attendance_owner_removed', $owner, $employee, ['old' => $before]);

        return back()->with('success', ($employee?->name ?? 'That person').' removed. They now see only their own attendance and that of anyone who reports to them.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withEmployee = false): array
    {
        $rules = [
            'title' => 'nullable|string|max:150',
            'scope' => 'required|in:'.implode(',', array_keys(AttendanceOwner::SCOPES)),
            'branch_ids' => 'nullable|required_if:scope,'.AttendanceOwner::SCOPE_BRANCHES.'|array',
            'branch_ids.*' => 'integer|exists:branches,id',
        ];

        if ($withEmployee) {
            $rules['employee'] = 'required|string|max:255';
        }

        return $request->validate($rules, [
            'branch_ids.required_if' => 'Tick at least one branch, or choose Whole company.',
        ]);
    }

    /** @return array<string, mixed> the columns both forms set */
    private function attributes(array $data): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
        sort($branchIds);

        $title = trim((string) ($data['title'] ?? ''));

        return [
            'title' => $title !== '' ? $title : null,
            'scope' => $data['scope'],
            'branch_ids' => $data['scope'] === AttendanceOwner::SCOPE_BRANCHES ? $branchIds : null,
            'updated_by' => Auth::id(),
        ];
    }

    /**
     * The picker's value starts with the employee id: "123 · Name · Oracle 456 · Branch".
     * A linked secondary mailbox resolves to its primary record, which is
     * where the row belongs.
     */
    private function employeeFrom(string $value): ?Employee
    {
        if (! preg_match('/^\s*#?(\d+)/', $value, $m)) {
            return null;
        }

        $employee = Employee::find((int) $m[1]);

        return $employee?->linked_primary_employee_id ? ($employee->linkedPrimary ?? $employee) : $employee;
    }

    /** @return array{title: ?string, access: string} */
    private function describe(AttendanceOwner $owner): array
    {
        return [
            'title' => $owner->title,
            'access' => $owner->accessLabel($this->branches()),
        ];
    }

    private function audit(string $action, AttendanceOwner $owner, ?Employee $employee, array $changes): void
    {
        ActivityLog::create([
            'model_type' => AttendanceOwner::class,
            'model_id' => $owner->id,
            'model_label' => $employee?->name,
            'action' => $action,
            'changes' => ['employee_id' => $owner->employee_id] + $changes,
            'user_id' => Auth::id(),
        ]);
    }

    /** @return Collection<int, Branch> keyed by id */
    private function branches(): Collection
    {
        return $this->branches ??= Branch::orderBy('name')->get(['id', 'name'])->keyBy('id');
    }
}
