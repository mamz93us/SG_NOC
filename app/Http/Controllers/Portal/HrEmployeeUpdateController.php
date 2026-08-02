<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use App\Services\Workflow\WorkflowEngine;
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
    public const WORKFLOW_TYPE = 'employee_update';

    /**
     * Fields HR may request a change to: field => [label, type].
     * `type` drives both validation and how the value is rendered in the diff.
     */
    public const EDITABLE_FIELDS = [
        'name' => ['label' => 'Full Name',       'type' => 'string'],
        'job_title' => ['label' => 'Job Title',       'type' => 'string'],
        'department_id' => ['label' => 'Department',      'type' => 'department'],
        'branch_id' => ['label' => 'Branch',          'type' => 'branch'],
        'manager_id' => ['label' => 'Manager',         'type' => 'employee'],
        'mobile_phone' => ['label' => 'Mobile Phone',    'type' => 'string'],
        'work_phone' => ['label' => 'Work Phone',      'type' => 'string'],
        'office_location' => ['label' => 'Office Location', 'type' => 'string'],
    ];

    public function __construct(private WorkflowEngine $engine) {}

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

        $pending = $employee
            ? WorkflowRequest::where('type', self::WORKFLOW_TYPE)
                ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'failed'])
                ->get()
                ->first(fn ($w) => (int) ($w->payload['employee_id'] ?? 0) === $employee->id)
            : null;

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
            'name' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'department_id' => 'nullable|integer|exists:departments,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'manager_id' => 'nullable|integer|exists:employees,id',
            'mobile_phone' => 'nullable|string|max:50',
            'work_phone' => 'nullable|string|max:50',
            'office_location' => 'nullable|string|max:255',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        if ($validated['manager_id'] ?? null) {
            if ((int) $validated['manager_id'] === $employee->id) {
                return back()->withInput()->with('error', 'An employee cannot be their own manager.');
            }
        }

        // Block a second in-flight request for the same employee — otherwise two
        // approvals would apply diffs computed against stale values.
        $inFlight = WorkflowRequest::where('type', self::WORKFLOW_TYPE)
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'failed'])
            ->get()
            ->first(fn ($w) => (int) ($w->payload['employee_id'] ?? 0) === $employee->id);

        if ($inFlight) {
            return back()->withInput()->with(
                'error',
                "Request #{$inFlight->id} for {$employee->name} is still open. Wait for IT to action it first."
            );
        }

        $changes = $this->buildDiff($employee, $validated);

        if (empty($changes)) {
            return back()->withInput()->with('error', 'Nothing changed — adjust at least one field before submitting.');
        }

        $payload = [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'employee_email' => $employee->email,
            'changes' => $changes,
            'reason' => $validated['reason'],
            'submitted_by_hr' => true,
            'hr_submitter_id' => Auth::id(),
            'hr_submitter_name' => Auth::user()->name ?? null,
        ];

        $summary = collect($changes)->pluck('label')->join(', ');

        $workflow = $this->engine->createRequest(
            type: self::WORKFLOW_TYPE,
            payload: $payload,
            branchId: $employee->branch_id,
            requestedBy: Auth::id(),
            title: "Update employee: {$employee->name}",
            description: "HR requests changes to {$summary}.\n\nReason: {$validated['reason']}",
        );

        return redirect()
            ->route('portal.hr.employee-update.index')
            ->with('success', "Change request #{$workflow->id} submitted for {$employee->name}. "
                .'Nothing has been changed yet — IT will review it.');
    }

    /**
     * Compare the submitted values against the employee's current values and
     * return only what actually differs, with display labels for both sides so
     * the approval screen can render a readable diff without re-querying.
     *
     * @return array<int, array{field:string,label:string,from:mixed,to:mixed,from_label:?string,to_label:?string}>
     */
    private function buildDiff(Employee $employee, array $input): array
    {
        $changes = [];

        foreach (self::EDITABLE_FIELDS as $field => $meta) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $new = $input[$field];
            $old = $employee->{$field};

            // Normalise before comparing: blank string and null are the same
            // "no value", and ids compare as integers.
            if (in_array($meta['type'], ['department', 'branch', 'employee'], true)) {
                $new = $new !== null && $new !== '' ? (int) $new : null;
                $old = $old !== null ? (int) $old : null;
            } else {
                $new = $new !== null ? trim((string) $new) : '';
                $old = $old !== null ? trim((string) $old) : '';
                if ($new === '' && $old === '') {
                    continue;
                }
            }

            if ($new === $old) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $meta['label'],
                'from' => $old,
                'to' => $new,
                'from_label' => $this->labelFor($meta['type'], $old),
                'to_label' => $this->labelFor($meta['type'], $new),
            ];
        }

        return $changes;
    }

    /**
     * Resolve a foreign-key value to a human name for the diff view.
     */
    private function labelFor(string $type, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'department' => Department::find($value)?->name,
            'branch' => Branch::find($value)?->name,
            'employee' => Employee::find($value)?->name,
            default => (string) $value,
        };
    }
}
