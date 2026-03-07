<?php

namespace App\Jobs;

use App\Models\WorkflowRequest;
use App\Services\Workflow\UserProvisioningService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(private int $workflowId) {}

    public function handle(): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);

        if (! $workflow) {
            Log::warning("ExecuteWorkflowJob: workflow #{$this->workflowId} not found.");
            return;
        }

        if ($workflow->status !== 'executing') {
            Log::info("ExecuteWorkflowJob: workflow #{$this->workflowId} status is {$workflow->status}, skipping.");
            return;
        }

        $engine        = app(WorkflowEngine::class);
        $notifications = app(NotificationService::class);

        try {
            $engine->logEvent($workflow, 'info', 'Execution job started.');

            $provisioning = app(UserProvisioningService::class);

            match ($workflow->type) {
                'create_user'      => $provisioning->provisionUser($workflow),
                'delete_user'      => $provisioning->deprovisionUser($workflow),
                'license_purchase' => $engine->logEvent($workflow, 'info', 'License purchase request approved — procurement team to proceed manually.'),
                default            => $engine->logEvent($workflow, 'info', "Workflow type '{$workflow->type}' has no automated execution handler."),
            };

            $workflow->update(['status' => 'completed']);
            $engine->logEvent($workflow, 'success', 'Workflow execution completed successfully.');

            if ($workflow->requested_by) {
                $notifications->notify(
                    $workflow->requested_by,
                    'workflow_complete',
                    "Request Completed — {$workflow->title}",
                    'Your request has been fully processed and completed.',
                    route('admin.workflows.show', $workflow->id),
                    'info'
                );
            }

            Log::info("ExecuteWorkflowJob: workflow #{$this->workflowId} completed.");

        } catch (\Throwable $e) {
            Log::error("ExecuteWorkflowJob: workflow #{$this->workflowId} failed — " . $e->getMessage());
            $engine->markFailed($workflow, $e->getMessage());
        }
    }
}
