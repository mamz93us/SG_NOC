<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\OnboardingManagerToken;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrOnboardingController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * POST /api/hr/onboarding
     *
     * Accepted JSON body:
     * {
     *   "first_name":       "Ahmed",
     *   "last_name":        "Karimi",
     *   "job_title":        "Software Engineer",
     *   "department":       "Engineering",
     *   "department_id":    3,
     *   "department_name":  "Engineering",
     *   "branch_id":        1,
     *   "start_date":       "2026-04-01",
     *   "manager_email":    "manager@company.com",
     *   "upn_domain":       "company.com",
     *   "hr_reference":     "HR-2026-0045",
     *   "mobile_phone":     "+966XXXXXXXXX",
     *   "notes":            "VIP employee — expedite"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'job_title'       => 'nullable|string|max:150',
            'department'      => 'nullable|string|max:150',
            'department_id'   => 'nullable|integer|exists:departments,id',
            'department_name' => 'nullable|string|max:150',
            'branch_id'       => 'required|integer|exists:branches,id',
            'start_date'      => 'nullable|date',
            'manager_email'   => 'nullable|email|max:200',
            'upn_domain'      => 'nullable|string|max:100',
            'hr_reference'    => 'nullable|string|max:100',
            'mobile_phone'    => 'nullable|string|max:30',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $branchId    = (int) $data['branch_id'];
        $deptId      = isset($data['department_id']) ? (int) $data['department_id'] : null;
        $displayName = trim($data['first_name'] . ' ' . $data['last_name']);

        // Resolve department name for payload
        $deptName = $data['department_name']
            ?? $data['department']
            ?? ($deptId ? Department::find($deptId)?->name : null);

        $payload = array_merge($data, [
            'display_name'   => $displayName,
            'department'     => $deptName,
            'department_id'  => $deptId,
            'source'         => 'hr_api',
        ]);

        $workflow = $this->engine->createRequest(
            type:        'create_user',
            payload:     $payload,
            branchId:    $branchId,
            requestedBy: null,        // system-initiated via API
            title:       "Onboard: {$displayName}",
            description: $data['notes'] ?? null,
        );

        // Create manager setup token immediately (synchronous) so the form link
        // is available on the workflow show page. The email is NOT sent now —
        // it is dispatched by WorkflowEngine after IT approval completes.
        if (! empty($data['manager_email'])) {
            $managerEmail = $data['manager_email'];
            $managerName  = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');

            OnboardingManagerToken::generate($workflow->id, [
                'manager_email' => $managerEmail,
                'manager_name'  => $managerName,
            ]);
        }

        return response()->json([
            'ok'          => true,
            'workflow_id' => $workflow->id,
            'status'      => $workflow->status,
            'message'     => "Onboarding workflow created for {$displayName}.",
        ], 201);
    }
}
