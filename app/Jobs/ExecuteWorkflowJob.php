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
                'create_user'          => $provisioning->provisionUser($workflow),
                'delete_user'          => $provisioning->deprovisionUser($workflow),
                'license_purchase'     => $engine->logEvent($workflow, 'info', 'License purchase request approved — procurement team to proceed manually.'),
                'profile_update_phone' => $this->applyPhoneUpdate($workflow, $engine),
                default                => $engine->logEvent($workflow, 'info', "Workflow type '{$workflow->type}' has no automated execution handler."),
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

    /**
     * Apply an approved phone-update request to the employee's linked Contact.
     * Creates a Contact if one doesn't exist yet.
     */
    private function applyPhoneUpdate(WorkflowRequest $workflow, WorkflowEngine $engine): void
    {
        $payload = $workflow->payload ?? [];
        $employeeId = $payload['employee_id'] ?? null;
        $newPhone   = $payload['new_value']   ?? null;

        if (!$employeeId || $newPhone === null) {
            $engine->logEvent($workflow, 'warning', 'Phone update payload incomplete — nothing to apply.');
            return;
        }

        $employee = \App\Models\Employee::find($employeeId);
        if (!$employee) {
            $engine->logEvent($workflow, 'warning', "Employee #{$employeeId} no longer exists — skipping phone update.");
            return;
        }

        if ($employee->contact_id) {
            \App\Models\Contact::where('id', $employee->contact_id)->update(['phone' => $newPhone]);
        } else {
            $nameParts = preg_split('/\s+/', trim((string) $employee->name), 2);
            $contact = \App\Models\Contact::create([
                'first_name' => $nameParts[0] ?? ($employee->name ?? ''),
                'last_name'  => $nameParts[1] ?? '',
                'job_title'  => $employee->job_title,
                'phone'      => $newPhone,
                'email'      => $employee->email,
                'branch_id'  => $employee->branch_id,
                'source'     => 'profile_update_phone',
            ]);
            $employee->contact_id = $contact->id;
            $employee->save();
        }

        $engine->logEvent($workflow, 'success', "Phone updated to {$newPhone} for {$employee->name}.");
    }
}
