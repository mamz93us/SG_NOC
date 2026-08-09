<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workflow\OffboardingRequestService;
use App\Support\HrLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/hr/offboarding — raise a termination request.
 *
 * Mirrors the HR portal offboarding form: HR identifies the leaver and supplies
 * the termination details only. Everything about the person — name, work email,
 * branch, reporting manager — is read off the employee record rather than being
 * retyped, so nothing about them can be mistyped.
 *
 * The heavy lifting (workflow + Graph enrichment + state row + manager decision
 * token + email) lives in OffboardingRequestService, shared with the form.
 */
class HrOffboardingController extends Controller
{
    public function __construct(private OffboardingRequestService $offboarding) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Identify the leaver by any one of these.
            'employee_id' => 'nullable|integer',
            'oracle_emp_no' => 'nullable|string|max:50',
            'upn' => 'nullable|email|max:200',
            'employee_name' => 'nullable|string|max:200',

            // Termination details — the only part HR actually enters.
            'last_day' => 'required|date|after_or_equal:today',
            'reason' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',

            // Optional: only when the record has no manager, or HR deliberately
            // routes the decision form elsewhere.
            'manager_override_id' => 'nullable|integer',
            'manager_email' => 'nullable|email|max:200',
            'manager_name' => 'nullable|string|max:200',

            'hr_reference' => 'nullable|string|max:50',
        ]);

        // ── Resolve the leaver ──────────────────────────────────────────────
        $employee = HrLookup::employee(
            id: $data['employee_id'] ?? null,
            oracleEmpNo: $data['oracle_emp_no'] ?? null,
            email: $data['upn'] ?? null,
            name: $data['employee_name'] ?? null,
        );

        if (! $employee) {
            return $this->fail(
                'Could not identify the employee. Send employee_id, oracle_emp_no or upn.',
                ['field' => 'employee']
            );
        }

        if (! $employee->email) {
            return $this->fail(
                "{$employee->name} has no work email on file, so there is no mailbox to offboard. Fix the record in NOC first.",
                ['field' => 'employee', 'employee_id' => $employee->id]
            );
        }

        // ── Resolve who receives the decision form ──────────────────────────
        $manager = HrLookup::employee(
            id: $data['manager_override_id'] ?? null,
            email: $data['manager_email'] ?? null,
        ) ?? $employee->manager;

        $managerEmail = $manager?->email ?? ($data['manager_email'] ?? null);

        if (! $managerEmail) {
            return $this->fail(
                'This employee has no manager with a work email on file. Send manager_override_id or manager_email '
                .'to say who should receive the decision form.',
                ['field' => 'manager', 'employee_id' => $employee->id]
            );
        }

        if ($manager && $manager->id === $employee->id) {
            return $this->fail('The leaver cannot be their own approver — name a different manager.', ['field' => 'manager']);
        }

        try {
            $offboardingWorkflow = $this->offboarding->create([
                'employee_id' => $employee->id,
                'upn' => $employee->email,
                'employee_name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'manager_email' => $managerEmail,
                'manager_name' => $manager?->name ?? ($data['manager_name'] ?? null),
                'last_day' => $data['last_day'],
                'reason' => $data['reason'] ?? null,
                // The Oracle number is the reference; taken from the record so
                // it is right even when the caller omits it.
                'hr_reference' => $data['hr_reference'] ?? $data['oracle_emp_no'] ?? $employee->oracle_emp_no,
                'oracle_emp_no' => $employee->oracle_emp_no ?? ($data['oracle_emp_no'] ?? null),
                'notes' => $data['notes'] ?? null,
            ], requestedBy: null, source: 'hr_api');
        } catch (\RuntimeException $e) {
            // Disabled module → 503; anything else is a bad request payload.
            $status = str_contains($e->getMessage(), 'disabled') ? 503 : 422;

            return $this->fail($e->getMessage(), [], $status);
        }

        return response()->json([
            'ok' => true,
            'workflow_id' => $offboardingWorkflow->workflow_id,
            'offboarding_workflow_id' => $offboardingWorkflow->id,
            'status' => 'manager_input_pending',
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'upn' => $employee->email,
                'oracle_emp_no' => $employee->oracle_emp_no,
            ],
            'manager_email' => $managerEmail,
            'expected_last_day' => $data['last_day'],
            'message' => "Offboarding request created. Manager decision form sent to {$managerEmail}. "
                .'Deprovisioning starts once they respond.',
        ], 201);
    }

    private function fail(string $message, array $extra = [], int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message] + $extra, $status);
    }
}
