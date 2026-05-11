<?php

namespace App\Services\Workflow;

use App\Jobs\Azure\DeleteAzureUserJob;
use App\Jobs\Azure\DisableAzureUserJob;
use App\Jobs\Offboarding\EscalateToItJob;
use App\Jobs\Offboarding\RemoveMailboxForwardingJob;
use App\Jobs\Offboarding\SendManagerReminderJob;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The lifecycle daemon — runs once a day from RunOffboardingScheduler.
 *
 *   1. On expected_last_day → DisableAzureUserJob + start the grace timer.
 *   2. During grace period without manager response → daily reminder.
 *   3. After grace period without manager response → escalate to IT.
 *   4. On forward_until → RemoveMailboxForwardingJob.
 *   5. On delete_after (and backups complete) → DeleteAzureUserJob.
 */
class OffboardingScheduler
{
    public function __construct(private WorkflowEngine $engine) {}

    public function run(?Carbon $today = null): array
    {
        $today    = ($today ?? now())->startOfDay();
        $settings = Setting::get();
        $graceDays = (int) ($settings->offboarding_manager_grace_days ?? 3);

        $rows = OffboardingWorkflow::query()
            ->with(['employee', 'workflow', 'backups'])
            ->whereNotIn('status', ['completed', 'cancelled', 'failed'])
            ->get();

        $summary = [
            'disabled'          => 0,
            'reminded'          => 0,
            'escalated'         => 0,
            'forwarding_removed'=> 0,
            'deleted'           => 0,
        ];

        foreach ($rows as $ow) {
            try {
                // ── 1. Auto-disable on expected_last_day ─────────────────────
                if (! $ow->azure_disabled_at && $ow->expected_last_day && $today->gte($ow->expected_last_day)) {
                    if ($ow->employee?->azure_id) {
                        DisableAzureUserJob::dispatch($ow->employee->azure_id);
                    }
                    $ow->update([
                        'azure_disabled_at'   => now(),
                        'manager_grace_until' => $today->copy()->addDays($graceDays)->toDateString(),
                        'status'              => $ow->status === 'manager_input_pending' ? 'manager_input_pending' : 'active',
                    ]);
                    if ($ow->employee) {
                        $ow->employee->update([
                            'status'          => 'terminated',
                            'terminated_date' => $today->toDateString(),
                        ]);
                    }
                    $this->engine->logEvent($ow->workflow, 'warning',
                        'Auto-disabled Azure user on expected last day.');
                    $summary['disabled']++;
                }

                // ── 2/3. Manager grace + escalation ──────────────────────────
                if ($ow->status === 'manager_input_pending' && $ow->expected_last_day && $today->gt($ow->expected_last_day)) {
                    if ($ow->manager_grace_until && $today->lte($ow->manager_grace_until)) {
                        SendManagerReminderJob::dispatch($ow->id);
                        $summary['reminded']++;
                    } elseif (! $ow->escalated_at) {
                        EscalateToItJob::dispatch($ow->id);
                        $ow->update(['escalated_at' => now(), 'status' => 'escalated']);
                        $summary['escalated']++;
                    }
                }

                // ── 4. Forward window expired ────────────────────────────────
                if ($ow->forward_until
                    && $ow->forward_rule_id
                    && $today->gte($ow->forward_until)
                ) {
                    RemoveMailboxForwardingJob::dispatch($ow->id);
                    $summary['forwarding_removed']++;
                }

                // ── 5. Final Azure delete ────────────────────────────────────
                if (! $ow->azure_deleted_at
                    && $ow->delete_after
                    && $today->gte($ow->delete_after)
                    && $ow->allBackupsComplete()
                    && $ow->employee?->azure_id
                ) {
                    DeleteAzureUserJob::dispatch($ow->employee->azure_id);
                    $ow->update([
                        'azure_deleted_at' => now(),
                        'status'           => 'completed',
                        'completed_at'     => now(),
                    ]);
                    $this->engine->logEvent($ow->workflow, 'success',
                        'Azure user delete dispatched (retention window complete).');
                    $summary['deleted']++;
                }
            } catch (\Throwable $e) {
                Log::warning('OffboardingScheduler::run row failed', [
                    'offboarding_workflow_id' => $ow->id,
                    'error'                   => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }
}
