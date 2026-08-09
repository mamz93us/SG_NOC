<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use App\Services\Workflow\EmployeeUpdateRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * HR-initiated employee data changes.
 *
 * HR never writes to the Employee record directly — the form computes a diff
 * and raises an `employee_update` WorkflowRequest for IT to approve. The apply
 * step (write to Employee + push to Azure) is handled by the workflow engine
 * on approval.
 *
 * Email / UPN is deliberately NOT editable here: changing it cascades into the
 * mailbox, Azure sign-in and email signatures, so it goes through IT directly.
 */
class HrEmployeeUpdateController extends Controller
{
    public const WORKFLOW_TYPE = EmployeeUpdateRequestService::WORKFLOW_TYPE;

    /** @see EmployeeUpdateRequestService::EDITABLE_FIELDS — kept as an alias for the views. */
    public const EDITABLE_FIELDS = EmployeeUpdateRequestService::EDITABLE_FIELDS;

    public function __construct(private EmployeeUpdateRequestService $updates) {}

    /**
     * GET /portal/hr/employee-update
     */
    public function index(): View
    {
        $requests = WorkflowRequest::with('branch')
            ->where('type', self::WORKFLOW_TYPE)
            ->where('requested_by', Auth::id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('portal.hr.employee_update.index', compact('requests'));
    }

    /**
     * GET /portal/hr/employee-update/create?employee=123
     */
    public function create(Request $request): View
    {
        $employee = $request->filled('employee')
            ? Employee::with(['branch', 'department', 'manager'])->find($request->query('employee'))
            : null;

        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Department::orderBy('name')->get(['id', 'name']);

        // Only load the manager picker list once an employee is selected.
        $managers = $employee
            ? Employee::where('status', 'active')
                ->whereKeyNot($employee->id)
                ->orderBy('name')
                ->get(['id', 'name', 'job_title'])
            : collect();

        $pending = $employee ? $this->updates->openRequestFor($employee->id) : null;

        $fields = self::EDITABLE_FIELDS;

        return view('portal.hr.employee_update.create', compact(
            'employee', 'branches', 'departments', 'managers', 'pending', 'fields'
        ));
    }

    /**
     * POST /portal/hr/employee-update
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'reason' => 'required|string|max:1000',
        ] + EmployeeUpdateRequestService::FIELD_RULES);

        try {
            $workflow = $this->updates->create($validated, requestedBy: Auth::id(), source: 'hr_portal');
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $employeeName = $workflow->payload['employee_name'] ?? 'the employee';

        return redirect()
            ->route('portal.hr.employee-update.index')
            ->with('success', "Change request #{$workflow->id} submitted for {$employeeName}. "
                .'Nothing has been changed yet — IT will review it.');
    }
}
