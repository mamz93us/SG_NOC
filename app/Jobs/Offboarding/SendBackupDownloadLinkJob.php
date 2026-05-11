<?php

namespace App\Jobs\Offboarding;

use App\Mail\BackupDownloadReadyMail;
use App\Models\OffboardingBackup;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendBackupDownloadLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $backupId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $backup = OffboardingBackup::with('offboardingWorkflow.token', 'offboardingWorkflow.employee')->find($this->backupId);
        if (! $backup || ! $backup->isDownloadable()) return;

        $managerEmail = $backup->offboardingWorkflow?->token?->manager_email;
        if (! $managerEmail) return;

        $settings = Setting::get();
        $cc       = $settings->offboarding_it_escalation_email ?: null;

        $mail = Mail::to($managerEmail);
        if ($cc) {
            $mail->cc($cc);
        }

        $mail->send(new BackupDownloadReadyMail($backup));

        $backup->update(['manager_notified_at' => now()]);
    }
}
