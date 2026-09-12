<?php

namespace App\Observers;

use App\Models\WorkflowRequest;
use App\Services\NotificationService;

/**
 * Requester notifications on terminal status transitions.
 *
 * The create / update / delete audit rows this used to write are now the generic
 * AuditObserver's job — it covers the same events for every model, records them
 * whether or not a user is signed in (the workflow engine's own transitions run
 * in the queue), and diffs only what changed.
 */
class WorkflowRequestObserver
{
    public function updated(WorkflowRequest $model): void
    {
        // Notify the requester on terminal status transitions. Several code paths
        // flip status directly (ExecuteWorkflowJob, OffboardingFormController,
        // admin UI) without calling NotificationService — centralising it here
        // guarantees the requester hears back regardless of which path fires.
        if ($model->wasChanged('status')) {
            $this->notifyOnTerminalStatus($model);
        }
    }

    private function notifyOnTerminalStatus(WorkflowRequest $model): void
    {
        $terminal = ['completed', 'failed', 'rejected', 'cancelled'];
        if (! in_array($model->status, $terminal, true)) {
            return;
        }
        if (! $model->requested_by) {
            return;
        }

        try {
            $notifications = app(NotificationService::class);

            [$title, $message, $severity] = match ($model->status) {
                'completed' => [
                    "Request Completed — {$model->title}",
                    'Your workflow request has been completed successfully.',
                    'success',
                ],
                'rejected'  => [
                    "Request Rejected — {$model->title}",
                    'Your workflow request was rejected by an approver.',
                    'warning',
                ],
                'failed'    => [
                    "Request Failed — {$model->title}",
                    'Your workflow request failed during execution. Please contact IT.',
                    'critical',
                ],
                'cancelled' => [
                    "Request Cancelled — {$model->title}",
                    'Your workflow request was cancelled.',
                    'info',
                ],
            };

            $notifications->notify(
                $model->requested_by,
                'workflow_' . $model->status,
                $title,
                $message,
                \App\Support\Noc::route('admin.workflows.show', $model->id),
                $severity
            );
        } catch (\Throwable) {
            // Notification delivery must not block the workflow state change.
        }
    }
}
