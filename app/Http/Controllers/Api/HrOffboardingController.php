<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workflow\OffboardingRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrOffboardingController extends Controller
{
    public function __construct(private OffboardingRequestService $offboarding) {}

    /**
     * POST /api/hr/offboarding
     *
     * Accepted JSON body:
     * {
     *   "employee_id":    42,                 // optional if upn provided
     *   "upn":            "ahmed@co.com",     // primary identity — employee work email
     *   "employee_name":  "Ahmed Karimi",
     *   "last_day":       "2026-04-30",       // required — date of final access
     *   "reason":         "resignation",
     *   "manager_email":  "manager@co.com",
     *   "manager_name":   "Sarah Smith",
     *   "branch_id":      1,
     *   "hr_reference":   "HR-OFF-2026-012",
     *   "notes":          "..."
     * }
     *
     * The actual work (workflow + Graph enrichment + offboarding state row +
     * manager token + email) lives in OffboardingRequestService, shared with
     * the HR portal form.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer',
            'upn' => 'nullable|email|max:200',
            'employee_name' => 'required|string|max:200',
            'last_day' => 'required|date|after_or_equal:today',
            'reason' => 'nullable|string|max:100',
            'manager_email' => 'required|email|max:200',
            'manager_name' => 'nullable|string|max:200',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'hr_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $offboardingWorkflow = $this->offboarding->create($data, requestedBy: null, source: 'hr_api');
        } catch (\RuntimeException $e) {
            // Disabled module → 503; anything else is a bad request payload.
            $status = str_contains($e->getMessage(), 'disabled') ? 503 : 422;

            return response()->json(['ok' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'ok' => true,
            'workflow_id' => $offboardingWorkflow->workflow_id,
            'offboarding_workflow_id' => $offboardingWorkflow->id,
            'status' => 'manager_input_pending',
            'manager_email' => $data['manager_email'],
            'expected_last_day' => $data['last_day'],
            'message' => "Offboarding workflow created. Manager approval email sent to {$data['manager_email']}.",
        ], 201);
    }
}
