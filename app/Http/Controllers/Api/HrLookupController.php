<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingWorkflow;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only endpoints that make the write endpoints usable from an HR system.
 *
 * The portal forms resolve branches, departments and people through pickers.
 * An integration has no pickers, so it needs the same lists — and a way to
 * check what happened to a request it raised.
 */
class HrLookupController extends Controller
{
    /**
     * GET /api/hr/reference-data
     *
     * Everything an integration needs to map its own values onto NOC ids.
     */
    public function referenceData(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'branches' => Branch::orderBy('name')->get(['id', 'name'])->all(),
            'departments' => Department::orderBy('name')->get(['id', 'name'])->all(),
            'upn_domains' => AllowedDomain::orderByDesc('is_primary')->orderBy('domain')
                ->get(['domain', 'is_primary'])->all(),
            'genders' => ['male', 'female'],
            'editable_employee_fields' => array_map(
                fn ($meta) => $meta['label'],
                \App\Services\Workflow\EmployeeUpdateRequestService::EDITABLE_FIELDS
            ),
        ]);
    }

    /**
     * GET /api/hr/employees?query=&oracle_emp_no=&upn=&status=
     *
     * Directory search, used to turn a name or Oracle number into the id the
     * write endpoints prefer. Capped and never returns anything sensitive.
     */
    public function employees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:100',
            'oracle_emp_no' => 'nullable|string|max:50',
            'upn' => 'nullable|email|max:200',
            'status' => 'nullable|in:active,inactive,all',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Employee::query()->with(['branch:id,name', 'department:id,name', 'manager:id,name,email']);

        $status = $data['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($data['oracle_emp_no'])) {
            $query->where('oracle_emp_no', trim($data['oracle_emp_no']));
        }

        if (! empty($data['upn'])) {
            $query->where('email', trim($data['upn']));
        }

        if (! empty($data['query'])) {
            $term = '%'.trim($data['query']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('oracle_emp_no', 'like', $term);
            });
        }

        $employees = $query->orderBy('name')->limit($data['limit'] ?? 25)->get();

        return response()->json([
            'ok' => true,
            'count' => $employees->count(),
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'upn' => $e->email,
                'oracle_emp_no' => $e->oracle_emp_no,
                'job_title' => $e->job_title,
                'status' => $e->status,
                'branch' => $e->branch?->name,
                'branch_id' => $e->branch_id,
                'department' => $e->department?->name,
                'department_id' => $e->department_id,
                'manager' => $e->manager ? ['id' => $e->manager->id, 'name' => $e->manager->name, 'email' => $e->manager->email] : null,
                'extension' => $e->extension_number,
            ])->all(),
        ]);
    }

    /**
     * GET /api/hr/requests/{workflow}
     *
     * Where a request the caller raised has got to. Deliberately carries no
     * credentials — the initial password never leaves NOC through this API.
     */
    public function requestStatus(int $workflow): JsonResponse
    {
        $request = WorkflowRequest::find($workflow);

        if (! $request) {
            return response()->json(['ok' => false, 'message' => 'Request not found.'], 404);
        }

        $payload = $request->payload ?? [];

        $body = [
            'ok' => true,
            'workflow_id' => $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'title' => $request->title,
            'oracle_emp_no' => $payload['oracle_emp_no'] ?? $payload['hr_reference'] ?? null,
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];

        if ($request->type === 'create_user') {
            $body['employee'] = [
                'id' => $payload['employee_id'] ?? null,
                'display_name' => $payload['display_name'] ?? null,
                'upn' => $payload['upn'] ?? null,
                'extension' => $payload['extension'] ?? null,
                'start_date' => $payload['start_date'] ?? null,
            ];
            $body['manager_form'] = [
                'sent_to' => $payload['manager_email'] ?? null,
                'responded' => ! empty($payload['manager_form_completed_at'])
                    || ! empty($payload['laptop_status']),
            ];
        }

        if ($request->type === 'employee_offboarding') {
            $state = OffboardingWorkflow::where('workflow_id', $request->id)->first();
            $body['offboarding'] = [
                'state' => $state?->status,
                'expected_last_day' => $state?->expected_last_day,
                'manager_decision' => $payload['manager_decision'] ?? null,
                'completed_at' => $state?->completed_at?->toIso8601String(),
            ];
        }

        if ($request->type === 'employee_update') {
            $body['changes'] = array_map(fn ($c) => [
                'field' => $c['field'] ?? null,
                'label' => $c['label'] ?? null,
                'from' => $c['from_label'] ?? ($c['from'] ?? null),
                'to' => $c['to_label'] ?? ($c['to'] ?? null),
            ], $payload['changes'] ?? []);
        }

        return response()->json($body);
    }
}
