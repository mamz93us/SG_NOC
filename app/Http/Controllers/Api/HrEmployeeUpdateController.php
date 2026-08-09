<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workflow\EmployeeUpdateRequestService;
use App\Support\HrLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/hr/employee-update — request a change to an employee's data.
 *
 * Mirrors the HR portal data-change form. Nothing is written to the employee
 * record here: the service diffs the submitted values against the current ones
 * and raises an `employee_update` workflow for IT to approve. Only the fields
 * that actually differ end up in the request.
 *
 * Send only the fields you want changed — an omitted field is left alone, which
 * is not the same as sending it empty (that requests clearing the value).
 */
class HrEmployeeUpdateController extends Controller
{
    public function __construct(private EmployeeUpdateRequestService $updates) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Identify the employee by any one of these.
            'employee_id' => 'nullable|integer',
            'oracle_emp_no_lookup' => 'nullable|string|max:50',
            'upn' => 'nullable|email|max:200',

            'reason' => 'required|string|max:1000',

            // Name-based alternatives to the id fields.
            'department' => 'nullable|string|max:150',
            'branch' => 'nullable|string|max:150',
            'manager_email' => 'nullable|email|max:200',
        ] + EmployeeUpdateRequestService::FIELD_RULES);

        $employee = HrLookup::employee(
            id: $data['employee_id'] ?? null,
            oracleEmpNo: $data['oracle_emp_no_lookup'] ?? null,
            email: $data['upn'] ?? null,
        );

        if (! $employee) {
            return $this->fail(
                'Could not identify the employee. Send employee_id, oracle_emp_no_lookup or upn.',
                ['field' => 'employee']
            );
        }

        // Name-based references resolve to the id fields the diff works on.
        // Note oracle_emp_no is an *editable field* here; the lookup key is
        // deliberately a separate field so an employee's number can be corrected.
        $changes = array_intersect_key($data, EmployeeUpdateRequestService::FIELD_RULES);

        if (! empty($data['department'])) {
            $department = HrLookup::department(null, $data['department']);
            if (! $department) {
                return $this->fail("Unknown department \"{$data['department']}\".", ['field' => 'department']);
            }
            $changes['department_id'] = $department->id;
        }

        if (! empty($data['branch'])) {
            $branch = HrLookup::branch(null, $data['branch']);
            if (! $branch) {
                return $this->fail("Unknown branch \"{$data['branch']}\".", ['field' => 'branch']);
            }
            $changes['branch_id'] = $branch->id;
        }

        if (! empty($data['manager_email'])) {
            $manager = HrLookup::employee(email: $data['manager_email']);
            if (! $manager) {
                return $this->fail("No employee found with email {$data['manager_email']}.", ['field' => 'manager_email']);
            }
            $changes['manager_id'] = $manager->id;
        }

        if (empty($changes)) {
            return $this->fail(
                'No changeable fields were sent. Supported: '
                .implode(', ', array_keys(EmployeeUpdateRequestService::EDITABLE_FIELDS)).'.'
            );
        }

        try {
            $workflow = $this->updates->create(
                $changes + ['employee_id' => $employee->id, 'reason' => $data['reason']],
                requestedBy: null,
                source: 'hr_api',
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), ['employee_id' => $employee->id]);
        }

        return response()->json([
            'ok' => true,
            'workflow_id' => $workflow->id,
            'status' => $workflow->status,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'upn' => $employee->email,
                'oracle_emp_no' => $employee->oracle_emp_no,
            ],
            'changes' => array_map(fn ($c) => [
                'field' => $c['field'],
                'label' => $c['label'],
                'from' => $c['from_label'] ?? $c['from'],
                'to' => $c['to_label'] ?? $c['to'],
            ], $workflow->payload['changes'] ?? []),
            'message' => 'Change request created. Nothing has been changed yet — IT will review it.',
        ], 201);
    }

    private function fail(string $message, array $extra = [], int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message] + $extra, $status);
    }
}
