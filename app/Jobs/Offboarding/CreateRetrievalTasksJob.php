<?php

namespace App\Jobs\Offboarding;

use App\Models\Employee;
use App\Models\ItTask;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Creates one ItTask per asset the manager checked for retrieval.
 * Tasks are linked to the Employee being offboarded via polymorphic
 * `related_type` / `related_id`.
 */
class CreateRetrievalTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee.activeAssets.device', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow || ! $ow->employee) return;

        $choices = $ow->retrieval_choices ?? [];
        if (empty($choices)) {
            $engine->logEvent($ow->workflow, 'info', 'No retrieval tasks requested.');
            return;
        }

        $assignedToId = $this->resolveItAssignee();

        $created = 0;
        foreach ($ow->employee->activeAssets as $a) {
            if (empty($choices[$a->id])) continue;
            $deviceLabel = $a->device?->asset_code
                ?? $a->device?->serial_number
                ?? "Asset #{$a->asset_id}";
            $deviceType  = $a->device?->device_type ?? 'asset';

            ItTask::create([
                'title'        => "Retrieve {$deviceType}: {$deviceLabel} from {$ow->employee->name}",
                'description'  => "Offboarding #{$ow->id}. Last working day {$ow->expected_last_day?->format('Y-m-d')}.\n"
                                . "Asset {$deviceLabel} (serial {$a->device?->serial_number}) must be collected.",
                'type'         => 'offboarding_retrieval',
                'priority'     => 'medium',
                'status'       => 'open',
                'assigned_to'  => $assignedToId,
                'due_date'     => $ow->expected_last_day,
                'related_type' => Employee::class,
                'related_id'   => $ow->employee->id,
            ]);
            $created++;
        }

        $engine->logEvent($ow->workflow, 'success',
            "Created {$created} retrieval task(s).");
    }

    private function resolveItAssignee(): ?int
    {
        $settings = Setting::get();
        if ($email = $settings->offboarding_it_escalation_email) {
            $user = User::where('email', $email)->first();
            if ($user) return $user->id;
        }
        // Fallback: first super_admin / admin user.
        return User::whereIn('role', ['super_admin', 'admin'])->orderBy('id')->value('id');
    }
}
