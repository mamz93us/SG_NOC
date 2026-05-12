<?php

namespace App\Jobs\Avepoint;

use App\Models\AvepointBackup;
use App\Models\ItTask;
use App\Models\Setting;
use App\Models\User;
use App\Services\AvePoint\AvePointApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asks AvePoint for an export. Persists the job id and either schedules the
 * status poller (when private endpoints are configured) or flips the row to
 * `manual_upload_required` AND creates an IT task with clear instructions for
 * IT to fulfil via the admin upload form on the backup show page.
 */
class RequestAvepointExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(private int $backupId)
    {
        $this->onQueue('avepoint');
    }

    public function handle(AvePointApiService $avepoint): void
    {
        $backup = AvepointBackup::find($this->backupId);
        if (! $backup || $backup->status !== 'pending') return;

        try {
            $result = $backup->type === 'mailbox'
                ? $avepoint->requestMailboxExport($backup->subject_upn)
                : $avepoint->requestOneDriveExport($backup->subject_upn);

            $backup->update([
                'avepoint_job_id' => $result['job_id'] ?? null,
                'source'          => $result['mode'] === 'live' ? 'avepoint' : 'manual_upload',
                'status'          => $result['mode'] === 'live' ? 'running' : 'manual_upload_required',
            ]);

            if ($result['mode'] === 'live') {
                PollAvepointExportJob::dispatch($backup->id)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
            } else {
                $this->createManualUploadTask($backup);
            }
        } catch (\Throwable $e) {
            Log::error('RequestAvepointExportJob failed', [
                'backup_id' => $backup->id,
                'error'     => $e->getMessage(),
            ]);
            $backup->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function createManualUploadTask(AvepointBackup $backup): void
    {
        $subject = $backup->subject_name ?? $backup->subject_upn;
        $url     = route('admin.avepoint.backup.show', $backup);

        ItTask::create([
            'title'        => "AvePoint export ({$backup->type}) for {$subject}",
            'description'  => "An NOC admin requested an AvePoint backup that the public Graph API cannot trigger programmatically.\n\n"
                            . "Steps:\n"
                            . "1. Log into AvePoint Online Services → Cloud Backup for M365.\n"
                            . "2. Locate the most recent {$backup->type} backup for {$backup->subject_upn}.\n"
                            . "3. Run Export (mailbox → PST, OneDrive → ZIP) and download it.\n"
                            . "4. Upload via NOC: {$url}\n\n"
                            . "Notes from requester: " . ($backup->notes ?: '(none)'),
            'type'         => 'avepoint_manual_export',
            'priority'     => 'medium',
            'status'       => 'open',
            'assigned_to'  => $this->resolveItAssignee($backup),
            'due_date'     => now()->addDays(3),
            'related_type' => AvepointBackup::class,
            'related_id'   => $backup->id,
        ]);
    }

    private function resolveItAssignee(AvepointBackup $backup): ?int
    {
        // Prefer the requester themselves (they know the context) — fall back to
        // the IT escalation email user, then any super_admin/admin.
        if ($backup->requested_by_user_id) {
            return $backup->requested_by_user_id;
        }
        $email = Setting::get()->offboarding_it_escalation_email;
        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) return $user->id;
        }
        return User::whereIn('role', ['super_admin', 'admin'])->orderBy('id')->value('id');
    }
}
