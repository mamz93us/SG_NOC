<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOffboardingManagerRequestJob;
use App\Models\Employee;
use App\Models\OffboardingToken;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HrOffboardingController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

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
     * Behaviour:
     *  1. Creates a WorkflowRequest (type=employee_offboarding) in pending state.
     *  2. Fetches live Microsoft Graph data for the user (mailbox size,
     *     OneDrive size, mail-enabled groups, manager) and stores it in
     *     workflow payload so the manager email + form can display it.
     *  3. Creates the OffboardingWorkflow state row with status='manager_input_pending'.
     *  4. Generates an OffboardingToken with an expiry that covers the form
     *     window + manager grace days + a 7-day reminder buffer.
     *  5. Queues SendOffboardingManagerRequestJob.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'    => 'nullable|integer',
            'upn'            => 'nullable|email|max:200',
            'employee_name'  => 'required|string|max:200',
            'last_day'       => 'required|date|after_or_equal:today',
            'reason'         => 'nullable|string|max:100',
            'manager_email'  => 'required|email|max:200',
            'manager_name'   => 'nullable|string|max:200',
            'branch_id'      => 'nullable|integer|exists:branches,id',
            'hr_reference'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $settings = Setting::get();
        if (! $settings->offboarding_enabled) {
            return response()->json([
                'ok'      => false,
                'message' => 'Offboarding workflow is currently disabled in NOC settings.',
            ], 503);
        }

        // ── Resolve local employee record ───────────────────────────────────
        $employee = null;
        if (! empty($data['employee_id'])) {
            $employee = Employee::find($data['employee_id']);
        }
        if (! $employee && ! empty($data['upn'])) {
            $employee = Employee::where('email', $data['upn'])->first();
        }

        $upn      = $data['upn'] ?? $employee?->email;
        $branchId = $data['branch_id'] ?? $employee?->branch_id;

        if (! $upn) {
            return response()->json([
                'ok'      => false,
                'message' => 'Either upn or a resolvable employee_id is required.',
            ], 422);
        }

        // ── Live Microsoft Graph enrichment ─────────────────────────────────
        $liveData = $this->fetchLiveGraphData($upn);

        $payload = array_merge($data, [
            'employee_id'      => $employee?->id,
            'display_name'     => $data['employee_name'],
            'upn'              => $upn,
            'source'           => 'hr_api',
            'live_graph_data'  => $liveData,
        ]);

        // ── Create workflow ─────────────────────────────────────────────────
        $workflow = $this->engine->createRequest(
            type:        'employee_offboarding',
            payload:     $payload,
            branchId:    $branchId,
            requestedBy: null,
            title:       "Offboard: {$data['employee_name']}",
            description: $data['notes'] ?? null,
        );

        // ── Create offboarding_workflow state row ───────────────────────────
        $offboardingWorkflow = OffboardingWorkflow::create([
            'workflow_id'        => $workflow->id,
            'employee_id'        => $employee?->id,
            'status'             => 'manager_input_pending',
            'expected_last_day'  => $data['last_day'],
        ]);

        // ── Generate token with extended expiry ─────────────────────────────
        $graceDays   = (int) ($settings->offboarding_manager_grace_days ?? 3);
        $tokenExpiry = now()
            ->parse($data['last_day'])
            ->addDays($graceDays + 7);  // form window + grace + reminder buffer

        $token = OffboardingToken::generate($workflow->id, [
            'employee_id'   => $employee?->id,
            'manager_email' => $data['manager_email'],
            'manager_name'  => $data['manager_name'] ?? null,
            'payload'       => $payload,
            'expires_at'    => $tokenExpiry,
        ]);

        // ── Queue manager email ─────────────────────────────────────────────
        SendOffboardingManagerRequestJob::dispatch($workflow->id, $token->token)
            ->onQueue('emails');

        return response()->json([
            'ok'                     => true,
            'workflow_id'            => $workflow->id,
            'offboarding_workflow_id' => $offboardingWorkflow->id,
            'status'                 => 'manager_input_pending',
            'manager_email'          => $data['manager_email'],
            'expected_last_day'      => $data['last_day'],
            'message'                => "Offboarding workflow created. Manager approval email sent to {$data['manager_email']}.",
        ], 201);
    }

    /**
     * Pull mailbox/OneDrive usage + mail-enabled group list + manager via Graph.
     * Falls back to a sparse-but-valid structure if Graph is unavailable —
     * the manager email still ships, just without those rows.
     */
    private function fetchLiveGraphData(string $upn): array
    {
        $defaults = [
            'mailbox'   => ['size_bytes' => null, 'item_count' => null],
            'onedrive'  => ['size_bytes' => null, 'file_count' => null],
            'groups'    => [],
            'manager'   => null,
            'azure_id'  => null,
            'job_title' => null,
            'department'=> null,
            'fetched_at'=> now()->toIso8601String(),
            'error'     => null,
        ];

        try {
            $graph = new GraphService();

            $azureUser = $graph->getUser($upn);
            $userId    = $azureUser['id'] ?? null;

            $mailbox  = $graph->getMailboxUsage($upn);
            $onedrive = $graph->getOneDriveUsage($upn);

            $groups = $userId
                ? $graph->listUserGroups($userId, excludeSecurity: true)
                : [];

            $manager = null;
            if ($userId) {
                try {
                    $managerRaw = $graph->getUserManager($userId);
                    if ($managerRaw) {
                        $manager = [
                            'display_name' => $managerRaw['displayName']        ?? null,
                            'mail'         => $managerRaw['mail']               ?? null,
                            'upn'          => $managerRaw['userPrincipalName']  ?? null,
                        ];
                    }
                } catch (\Throwable) {
                    // Manager fetch is optional
                }
            }

            return array_merge($defaults, [
                'azure_id'   => $userId,
                'job_title'  => $azureUser['jobTitle']    ?? null,
                'department' => $azureUser['department']  ?? null,
                'mailbox'    => $mailbox,
                'onedrive'   => $onedrive,
                'groups'     => array_map(fn($g) => [
                    'id'           => $g['id']          ?? null,
                    'display_name' => $g['displayName'] ?? null,
                ], $groups),
                'manager'    => $manager,
            ]);
        } catch (\Throwable $e) {
            Log::warning('HrOffboardingController::fetchLiveGraphData failed', [
                'upn'   => $upn,
                'error' => $e->getMessage(),
            ]);
            return array_merge($defaults, ['error' => $e->getMessage()]);
        }
    }
}
