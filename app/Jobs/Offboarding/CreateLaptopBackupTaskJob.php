<?php

namespace App\Jobs\Offboarding;

use App\Models\Employee;
use App\Models\ItTask;
use App\Models\OffboardingBackup;
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
 * Creates an IT task to physically extract data from the user's laptop, plus
 * a placeholder OffboardingBackup row in 'manual_upload_required' state.
 * When IT uploads the archive via the admin form, the backup row flips to
 * 'completed' and the OffboardingProcessor releases the Intune device delete.
 */
class CreateLaptopBackupTaskJob implements ShouldQueue
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
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow || ! $ow->employee) return;

        // Idempotency — don't double-create.
        if ($ow->backups()->where('type', 'laptop')->exists()) {
            return;
        }

        OffboardingBackup::create([
            'offboarding_workflow_id' => $ow->id,
            'type'                    => 'laptop',
            'source'                  => 'manual_upload',
            'status'                  => 'manual_upload_required',
        ]);

        $assignedToId = $this->resolveItAssignee();

        ItTask::create([
            'title'        => "Backup laptop data for {$ow->employee->name} (offboarding #{$ow->id})",
            'description'  => "Manager requested laptop-data backup before wipe.\n"
                            . "1. Physically retrieve or remotely connect to the laptop.\n"
                            . "2. Extract user folder (C:\\Users\\{username}\\Documents, Desktop, Downloads, etc.).\n"
                            . "3. Compress to a single .zip / .7z.\n"
                            . "4. Upload via NOC: " . route('admin.offboarding.show', $ow) . " (look for the laptop backup row).\n"
                            . "Intune device wipe is on hold until this upload completes.",
            'type'         => 'offboarding_laptop_backup',
            'priority'     => 'high',
            'status'       => 'open',
            'assigned_to'  => $assignedToId,
            'due_date'     => $ow->expected_last_day,
            'related_type' => Employee::class,
            'related_id'   => $ow->employee->id,
        ]);

        $engine->logEvent($ow->workflow, 'info',
            'Laptop backup IT task created (Intune wipe held until upload completes).');
    }

    private function resolveItAssignee(): ?int
    {
        $settings = Setting::get();
        if ($email = $settings->offboarding_it_escalation_email) {
            $user = User::where('email', $email)->first();
            if ($user) return $user->id;
        }
        return User::whereIn('role', ['super_admin', 'admin'])->orderBy('id')->value('id');
    }
}
