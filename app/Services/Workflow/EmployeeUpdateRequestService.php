<?php

namespace App\Services\Workflow;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkflowRequest;

/**
 * Single entry point for HR-initiated employee data changes.
 *
 * Shared by the HR portal form (Portal\HrEmployeeUpdateController) and the HR
 * API (Api\HrEmployeeUpdateController). Nothing here writes to the Employee
 * record — it computes a diff against the current values and raises an
 * `employee_update` WorkflowRequest for IT to approve. The apply step (write to
 * Employee + push to Azure) happens on approval in the workflow engine.
 *
 * Email / UPN is deliberately not editable: changing it cascades into the
 * mailbox, Azure sign-in and email signatures, so it goes through IT directly.
 */
class EmployeeUpdateRequestService
{
    public const WORKFLOW_TYPE = 'employee_update';

    /**
     * Fields HR may request a change to: field => [label, type].
     * `type` drives both validation and how the value is rendered in the diff.
     */
    public const EDITABLE_FIELDS = [
        'name' => ['label' => 'Full Name', 'type' => 'string'],
        'job_title' => ['label' => 'Job Title', 'type' => 'string'],
        'department_id' => ['label' => 'Department', 'type' => 'department'],
        'branch_id' => ['label' => 'Branch', 'type' => 'branch'],
        'manager_id' => ['label' => 'Manager', 'type' => 'employee'],
        'mobile_phone' => ['label' => 'Mobile Phone', 'type' => 'string'],
        'work_phone' => ['label' => 'Work Phone', 'type' => 'string'],
        'office_location' => ['label' => 'Office Location', 'type' => 'string'],
        'oracle_emp_no' => ['label' => 'Oracle Employee ID', 'type' => 'string'],
    ];

    /** Laravel validation rules for the editable fields, shared by both surfaces. */
    public const FIELD_RULES = [
        'name' => 'nullable|string|max:255',
        'job_title' => 'nullable|string|max:255',
        'department_id' => 'nullable|integer|exists:departments,id',
        'branch_id' => 'nullable|integer|exists:branches,id',
        'manager_id' => 'nullable|integer|exists:employees,id',
        'mobile_phone' => 'nullable|string|max:50',
        'work_phone' => 'nullable|string|max:50',
        'office_location' => 'nullable|string|max:255',
        'oracle_emp_no' => 'nullable|string|max:50',
    ];

    public function __construct(private WorkflowEngine $engine) {}

    /**
     * @param  array  $data  employee_id + reason + any subset of EDITABLE_FIELDS
     *
     * @throws \RuntimeException on a business-rule violation
     */
    public function create(array $data, ?int $requestedBy = null, string $source = 'hr_portal'): WorkflowRequest
    {
        $employee = Employee::find($data['employee_id'] ?? null);

        if (! $employee) {
            throw new \RuntimeException('Employee not found.');
        }

        if (! empty($data['manager_id']) && (int) $data['manager_id'] === $employee->id) {
            throw new \RuntimeException('An employee cannot be their own manager.');
        }

        // Block a second in-flight request for the same employee — otherwise two
        // approvals would apply diffs computed against stale values.
        if ($inFlight = $this->openRequestFor($employee->id)) {
            throw new \RuntimeException(
                "Request #{$inFlight->id} for {$employee->name} is still open. Wait for IT to action it first."
            );
        }

        $changes = $this->buildDiff($employee, $data);

        if (empty($changes)) {
            throw new \RuntimeException('Nothing changed — adjust at least one field before submitting.');
        }

        $payload = [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'employee_email' => $employee->email,
            'changes' => $changes,
            'reason' => $data['reason'],
            'source' => $source,
            'submitted_by_hr' => true,
            'hr_submitter_id' => $requestedBy,
        ];

        $summary = collect($changes)->pluck('label')->join(', ');

        return $this->engine->createRequest(
            type: self::WORKFLOW_TYPE,
            payload: $payload,
            branchId: $employee->branch_id,
            requestedBy: $requestedBy,
            title: "Update employee: {$employee->name}",
            description: "HR requests changes to {$summary}.\n\nReason: {$data['reason']}",
        );
    }

    /** The open change request for an employee, if any. */
    public function openRequestFor(int $employeeId): ?WorkflowRequest
    {
        return WorkflowRequest::where('type', self::WORKFLOW_TYPE)
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'failed'])
            ->get()
            ->first(fn ($w) => (int) ($w->payload['employee_id'] ?? 0) === $employeeId);
    }

    /**
     * Compare the submitted values against the employee's current values and
     * return only what actually differs, with display labels for both sides so
     * the approval screen can render a readable diff without re-querying.
     *
     * @return array<int, array{field:string,label:string,from:mixed,to:mixed,from_label:?string,to_label:?string}>
     */
    public function buildDiff(Employee $employee, array $input): array
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
    public function labelFor(string $type, mixed $value): ?string
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
