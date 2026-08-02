<?php

namespace App\Services\Workflow;

use App\Jobs\SendOffboardingManagerRequestJob;
use App\Models\Employee;
use App\Models\OffboardingToken;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for raising an offboarding (termination) request.
 *
 * Both the HR API (Api\HrOffboardingController) and the HR portal form
 * (Portal\HrOffboardingController) call this, so a termination raised from
 * either surface produces exactly the same artefacts:
 *
 *   1. WorkflowRequest (type=employee_offboarding), enriched with live Graph data
 *   2. OffboardingWorkflow state row (status=manager_input_pending)
 *   3. OffboardingToken for the manager's decision form
 *   4. Queued SendOffboardingManagerRequestJob
 *
 * Anything that creates only the WorkflowRequest is a dead end — the manager
 * form is what drives OffboardingProcessor.
 */
class OffboardingRequestService
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * @param  array{employee_id?:int|null, upn?:string|null, employee_name:string,
     *               last_day:string, reason?:string|null, manager_email:string,
     *               manager_name?:string|null, branch_id?:int|null,
     *               hr_reference?:string|null, notes?:string|null}  $data
     *
     * @throws \RuntimeException when offboarding is disabled or no UPN can be resolved
     */
    public function create(array $data, ?int $requestedBy = null, string $source = 'hr_api'): OffboardingWorkflow
    {
        $settings = Setting::get();

        if (! $settings->offboarding_enabled) {
            throw new \RuntimeException('Offboarding workflow is currently disabled in NOC settings.');
        }

        // ── Resolve local employee record ───────────────────────────────────
        $employee = null;
        if (! empty($data['employee_id'])) {
            $employee = Employee::find($data['employee_id']);
        }
        if (! $employee && ! empty($data['upn'])) {
            $employee = Employee::where('email', $data['upn'])->first();
        }

        $upn = $data['upn'] ?? $employee?->email;
        $branchId = $data['branch_id'] ?? $employee?->branch_id;

        if (! $upn) {
            throw new \RuntimeException('Either upn or a resolvable employee_id is required.');
        }

        // ── Live Microsoft Graph enrichment ─────────────────────────────────
        $payload = array_merge($data, [
            'employee_id' => $employee?->id,
            'display_name' => $data['employee_name'],
            'upn' => $upn,
            'source' => $source,
            'live_graph_data' => $this->fetchLiveGraphData($upn),
        ]);

        // ── Create workflow ─────────────────────────────────────────────────
        $workflow = $this->engine->createRequest(
            type: 'employee_offboarding',
            payload: $payload,
            branchId: $branchId,
            requestedBy: $requestedBy,
            title: "Offboard: {$data['employee_name']}",
            description: $data['notes'] ?? null,
        );

        // ── Offboarding state row ───────────────────────────────────────────
        $offboardingWorkflow = OffboardingWorkflow::create([
            'workflow_id' => $workflow->id,
            'employee_id' => $employee?->id,
            'status' => 'manager_input_pending',
            'expected_last_day' => $data['last_day'],
        ]);

        // ── Manager decision token (form window + grace + reminder buffer) ──
        $graceDays = (int) ($settings->offboarding_manager_grace_days ?? 3);
        $tokenExpiry = now()->parse($data['last_day'])->addDays($graceDays + 7);

        $token = OffboardingToken::generate($workflow->id, [
            'employee_id' => $employee?->id,
            'manager_email' => $data['manager_email'],
            'manager_name' => $data['manager_name'] ?? null,
            'payload' => $payload,
            'expires_at' => $tokenExpiry,
        ]);

        SendOffboardingManagerRequestJob::dispatch($workflow->id, $token->token)
            ->onQueue('emails');

        $this->engine->logEvent(
            $workflow,
            'info',
            "Offboarding raised via {$source}. Manager decision form sent to {$data['manager_email']}."
        );

        return $offboardingWorkflow;
    }

    /**
     * Pull mailbox/OneDrive usage + mail-enabled group list + manager via Graph.
     * Falls back to a sparse-but-valid structure if Graph is unavailable — the
     * manager email still ships, just without those rows.
     */
    public function fetchLiveGraphData(string $upn): array
    {
        $defaults = [
            'mailbox' => ['size_bytes' => null, 'item_count' => null],
            'onedrive' => ['size_bytes' => null, 'file_count' => null],
            'groups' => [],
            'manager' => null,
            'azure_id' => null,
            'job_title' => null,
            'department' => null,
            'fetched_at' => now()->toIso8601String(),
            'error' => null,
        ];

        try {
            $graph = new GraphService;

            $azureUser = $graph->getUser($upn);
            $userId = $azureUser['id'] ?? null;

            $mailbox = $graph->getMailboxUsage($upn);
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
                            'display_name' => $managerRaw['displayName'] ?? null,
                            'mail' => $managerRaw['mail'] ?? null,
                            'upn' => $managerRaw['userPrincipalName'] ?? null,
                        ];
                    }
                } catch (\Throwable) {
                    // Manager fetch is optional
                }
            }

            return array_merge($defaults, [
                'azure_id' => $userId,
                'job_title' => $azureUser['jobTitle'] ?? null,
                'department' => $azureUser['department'] ?? null,
                'mailbox' => $mailbox,
                'onedrive' => $onedrive,
                'groups' => array_map(fn ($g) => [
                    'id' => $g['id'] ?? null,
                    'display_name' => $g['displayName'] ?? null,
                ], $groups),
                'manager' => $manager,
            ]);
        } catch (\Throwable $e) {
            Log::warning('OffboardingRequestService::fetchLiveGraphData failed', [
                'upn' => $upn,
                'error' => $e->getMessage(),
            ]);

            return array_merge($defaults, ['error' => $e->getMessage()]);
        }
    }
}
