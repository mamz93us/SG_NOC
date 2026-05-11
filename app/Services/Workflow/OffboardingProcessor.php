<?php

namespace App\Services\Workflow;

use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the post-approval offboarding flow.
 *
 * Called from OffboardingFormController::submit when the manager approves.
 * Dispatches a fan of queued jobs (see app/Jobs/Offboarding/*) that execute
 * the manager's decisions:
 *
 *   - mandatory mailbox + OneDrive backups (AvePoint or manual-upload fallback)
 *   - laptop backup IT task (gates the Intune wipe)
 *   - Intune device delete (Defender removal)
 *   - Azure group cleanup
 *   - UCM extension delete
 *   - Mailbox forwarding rule (if email_action == 'forward')
 *   - License unassignment (after backup completes)
 *   - Asset moves + retrieval tasks
 *
 * Schedule-driven actions (auto-disable on last day, manager reminders,
 * grace-period escalation, final Azure user delete) live in
 * OffboardingScheduler — not here.
 */
class OffboardingProcessor
{
    public function __construct(private WorkflowEngine $engine) {}

    public function beginProcessing(OffboardingWorkflow $ow): void
    {
        $workflow = $ow->workflow;
        $settings = Setting::get();

        $this->engine->logEvent($workflow, 'info', 'OffboardingProcessor::beginProcessing started.');

        // ── 1. Add to offboarding Azure group (sign-in lockdown via CA) ─────
        \App\Jobs\Offboarding\AddToOffboardingGroupJob::dispatch($ow->id)->onQueue('offboarding');

        // ── 2. Mandatory mailbox + OneDrive backups ─────────────────────────
        \App\Jobs\Offboarding\StartAvePointMailboxExportJob::dispatch($ow->id)->onQueue('avepoint');
        \App\Jobs\Offboarding\StartAvePointOneDriveExportJob::dispatch($ow->id)->onQueue('avepoint');

        // ── 3. Laptop handling — gate the Intune wipe on laptop backup ──────
        if ($ow->laptop_action === 'backup') {
            \App\Jobs\Offboarding\CreateLaptopBackupTaskJob::dispatch($ow->id)->onQueue('offboarding');
            // RemoveIntuneDevicesJob is fired by the laptop upload completion handler.
        } else {
            \App\Jobs\Offboarding\RemoveIntuneDevicesJob::dispatch($ow->id)->onQueue('offboarding');
        }

        // ── 4. Remove from ALL Azure groups (skips the offboarding group) ───
        \App\Jobs\Offboarding\RemoveUserFromAllGroupsJob::dispatch($ow->id)
            ->delay(now()->addSeconds(20))   // let the offboarding-group add settle
            ->onQueue('offboarding');

        // ── 5. UCM extension delete ─────────────────────────────────────────
        $employee = $ow->employee;
        if ($employee && $employee->extension_number && $employee->ucm_server_id) {
            try {
                \App\Jobs\Ucm\DeleteUcmExtensionJob::dispatch(
                    $employee->ucm_server_id,
                    $employee->extension_number,
                )->onQueue('ucm');
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning',
                    "Could not dispatch UCM extension delete: {$e->getMessage()}");
            }
        }

        // ── 6. Mailbox forwarding (only when email_action='forward') ────────
        if ($ow->email_action === 'forward' && ! empty($ow->forward_emails)) {
            \App\Jobs\Offboarding\SetMailboxForwardingJob::dispatch($ow->id)->onQueue('offboarding');
        }
        // License unassignment is dispatched by StreamAvePointExportToAzureBlobJob
        // (or the manual-upload completion handler) when the mailbox backup
        // reaches 'completed' — never before, so the backup can read the
        // mailbox while licenses are still attached.

        // ── 7. Asset moves ──────────────────────────────────────────────────
        \App\Jobs\Offboarding\ApplyAssetDecisionsJob::dispatch($ow->id)->onQueue('offboarding');

        // ── 8. Retrieval tasks ──────────────────────────────────────────────
        \App\Jobs\Offboarding\CreateRetrievalTasksJob::dispatch($ow->id)->onQueue('offboarding');

        // ── 9. Compute final-delete date ────────────────────────────────────
        $deleteAfter = $ow->forward_until
            ? \Carbon\Carbon::parse($ow->forward_until)
            : \Carbon\Carbon::parse($ow->expected_last_day)
                ->addDays((int) ($settings->offboarding_retention_days ?? 30));

        $ow->update(['delete_after' => $deleteAfter->toDateString()]);

        $this->engine->logEvent($workflow, 'info',
            "OffboardingProcessor: final Azure delete scheduled for {$ow->delete_after}.");
    }

    /**
     * Called when a laptop backup completes (manual upload finishes).
     * Releases the Intune device delete that was held during the backup.
     */
    public function onLaptopBackupComplete(OffboardingWorkflow $ow): void
    {
        $this->engine->logEvent($ow->workflow, 'info', 'Laptop backup uploaded — releasing Intune device delete.');
        \App\Jobs\Offboarding\RemoveIntuneDevicesJob::dispatch($ow->id)->onQueue('offboarding');
    }

    /**
     * Called when the mailbox backup completes — runs the license action
     * the manager chose. (Done after backup so the backup can still read
     * the mailbox while licenses are attached.)
     */
    public function onMailboxBackupComplete(OffboardingWorkflow $ow): void
    {
        if ($ow->email_action === 'forward') {
            \App\Jobs\Offboarding\UnassignAllExceptExchangeOnlyJob::dispatch($ow->id)->onQueue('offboarding');
        } else {
            \App\Jobs\Offboarding\UnassignAllLicensesJob::dispatch($ow->id)->onQueue('offboarding');
        }
    }
}
