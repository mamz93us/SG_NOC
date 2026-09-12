<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Services\Attendance\EmployeeLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Attendance → Employee Mapping: which NOC employee each BioTime code
 * belongs to. Codes the automatic rule could not settle wait here for HR.
 */
class BiotimeEmployeeController extends Controller
{
    private const STATUSES = ['unlinked', 'ambiguous', 'none', 'auto', 'manual', 'all'];

    public function index(Request $request): View
    {
        $status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : 'unlinked';
        $source = $request->integer('source') ?: null;
        $search = trim((string) $request->input('q'));

        $query = BiotimeEmployee::query()
            // code_prefix too: it is what lookupCode() shows the code being searched as.
            ->with(['source:id,name,code_prefix', 'employee:id,name,oracle_emp_no,branch_id,status', 'employee.branch:id,name', 'confirmedBy:id,name']);

        $this->applyStatus($query, $status);

        if ($source) {
            $query->where('biotime_source_id', $source);
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('emp_code', 'like', "%{$search}%")
                ->orWhere('device_name', 'like', "%{$search}%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%")));
        }

        $rows = $query->orderByDesc('last_punch_at')->paginate(50)->withQueryString();

        $candidateIds = $rows->getCollection()->pluck('candidate_ids')->flatten()->filter()->unique()->all();

        $counts = [];
        foreach (self::STATUSES as $s) {
            $counts[$s] = $this->applyStatus(BiotimeEmployee::query(), $s)->count();
        }

        return view('admin.attendance.employees.index', [
            'rows' => $rows,
            'status' => $status,
            'source' => $source,
            'q' => $search,
            'counts' => $counts,
            'sources' => BiotimeSource::orderBy('name')->get(['id', 'name']),
            'candidates' => Employee::with('branch:id,name')->whereIn('id', $candidateIds)->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'status'])->keyBy('id'),
            // Only people who can link need the (long) picker list.
            'employeeOptions' => $request->user()?->can('manage-attendance')
                ? Employee::with('branch:id,name')->orderBy('name')->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'status'])
                : collect(),
        ]);
    }

    public function link(Request $request, BiotimeEmployee $biotimeEmployee, EmployeeLinker $linker): RedirectResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:255']);

        // The picker's value starts with the employee id: "123 · Name · Oracle 456 · Branch".
        if (! preg_match('/^\s*#?(\d+)/', $data['employee'], $m) || ! ($employee = Employee::find((int) $m[1]))) {
            return back()->with('error', 'Pick the employee from the list — start typing a name or Oracle number.');
        }

        $old = $biotimeEmployee->employee_id;
        $linker->linkManually($biotimeEmployee, $employee, Auth::id());
        $this->log($biotimeEmployee, 'linked', ['old_employee_id' => $old, 'employee_id' => $employee->id]);

        return back()->with('success', "BioTime code {$biotimeEmployee->emp_code} is now linked to {$employee->name}. Their days were rebuilt.");
    }

    public function noEmployee(BiotimeEmployee $biotimeEmployee, EmployeeLinker $linker): RedirectResponse
    {
        $old = $biotimeEmployee->employee_id;
        $linker->linkManually($biotimeEmployee, null, Auth::id());
        $this->log($biotimeEmployee, 'marked-not-employee', ['old_employee_id' => $old]);

        return back()->with('success', "BioTime code {$biotimeEmployee->emp_code} is marked as not a NOC employee.");
    }

    public function reset(BiotimeEmployee $biotimeEmployee, EmployeeLinker $linker): RedirectResponse
    {
        $old = $biotimeEmployee->employee_id;
        $linker->resetToAuto($biotimeEmployee);
        $biotimeEmployee->refresh();
        $this->log($biotimeEmployee, 'reset-to-auto', ['old_employee_id' => $old, 'employee_id' => $biotimeEmployee->employee_id]);

        return back()->with('success', "BioTime code {$biotimeEmployee->emp_code} is back on automatic matching: {$biotimeEmployee->methodLabel()}.");
    }

    /** Queued: every newly linked code rebuilds all the days it touches. */
    public function automatch(): RedirectResponse
    {
        AttendanceTask::queue('relink', ['source_id' => null], 'Re-match unmapped codes', Auth::id());

        return back()->with('success', 'Auto-matching queued — it starts within a minute. The banner above shows what it linked.');
    }

    private function applyStatus($query, string $status)
    {
        return match ($status) {
            'unlinked' => $query->whereNull('employee_id')->where(fn ($q) => $q
                ->whereNull('match_method')->orWhere('match_method', '!=', BiotimeEmployee::METHOD_MANUAL)),
            'ambiguous' => $query->where('match_method', BiotimeEmployee::METHOD_AMBIGUOUS),
            'none' => $query->where('match_method', BiotimeEmployee::METHOD_NONE),
            'auto' => $query->whereIn('match_method', [BiotimeEmployee::METHOD_AUTO, BiotimeEmployee::METHOD_AUTO_BRANCH]),
            'manual' => $query->where('match_method', BiotimeEmployee::METHOD_MANUAL),
            default => $query,
        };
    }

    private function log(BiotimeEmployee $biotimeEmployee, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => 'BiotimeEmployee',
            'model_id' => $biotimeEmployee->id,
            'action' => $action,
            'changes' => ['emp_code' => $biotimeEmployee->emp_code, 'source_id' => $biotimeEmployee->biotime_source_id] + $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
