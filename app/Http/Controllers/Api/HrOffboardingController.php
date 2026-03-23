<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOffboardingManagerRequestJob;
use App\Models\Employee;
use App\Models\OffboardingToken;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrOffboardingController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * POST /api/hr/offboarding
     *
     * Accepted JSON body:
     * {
     *   "employee_id":    42,             // internal DB id (optional if upn provided)
     *   "upn":            "ahmed@co.com", // primary identity — employee's work email
     *   "employee_name":  "Ahmed Karimi",
     *   "last_day":       "2026-04-30",
     *   "reason":         "resignation",
     *   "manager_email":  "manager@co.com",
     *   "manager_name":   "Sarah Smith",
     *   "forward_to":     "team@co.com",  // mailbox forwarding note
     *   "branch_id":      1,
     *   "hr_reference":   "HR-OFF-2026-012",
     *   "notes":          "..."
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'    => 'nullable|integer',
            'upn'            => 'nullable|email|max:200',
            'employee_name'  => 'required|string|max:200',
            'last_day'       => 'nullable|date',
            'reason'         => 'nullable|string|max:100',
            'manager_email'  => 'required|email|max:200',
            'manager_name'   => 'nullable|string|max:200',
            'forward_to'     => 'nullable|email|max:200',
            'branch_id'      => 'nullable|integer|exists:branches,id',
            'hr_reference'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:1000',
        ]);

        // Try to resolve local employee record for additional context
        $employee = null;
        if (! empty($data['employee_id'])) {
            $employee = Employee::find($data['employee_id']);
        }
        if (! $employee && ! empty($data['upn'])) {
            $employee = Employee::where('email', $data['upn'])->first();
        }

        $branchId = $data['branch_id'] ?? $employee?->branch_id;

        $payload = array_merge($data, [
            'employee_id'  => $employee?->id,
            'display_name' => $data['employee_name'],
            'upn'          => $data['upn'] ?? $employee?->email,
            'source'       => 'hr_api',
        ]);

        // Create workflow in manager_input_pending status — wait for manager response
        $workflow = $this->engine->createRequest(
            type:        'employee_offboarding',
            payload:     $payload,
            branchId:    $branchId,
            requestedBy: null,
            title:       "Offboard: {$data['employee_name']}",
            description: $data['notes'] ?? null,
        );

        // Create manager approval token (7-day expiry)
        $token = OffboardingToken::generate($workflow->id, [
            'employee_id'  => $employee?->id,
            'manager_email'=> $data['manager_email'],
            'manager_name' => $data['manager_name'] ?? null,
            'payload'      => $payload,
        ]);

        // Queue email to manager with the form link
        SendOffboardingManagerRequestJob::dispatch($workflow->id, $token->token)
            ->onQueue('emails');

        return response()->json([
            'ok'          => true,
            'workflow_id' => $workflow->id,
            'status'      => 'manager_input_pending',
            'message'     => "Offboarding workflow created. Manager approval email sent to {$data['manager_email']}.",
        ], 201);
    }
}
