<?php

namespace App\Jobs\Avepoint;

use App\Mail\AvepointBackupReadyMail;
use App\Models\AvepointBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the NOC user who requested this ad-hoc backup with the download
 * link. Silent if the requester has no email (e.g. system-triggered).
 */
class SendAvepointBackupReadyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $backupId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $backup = AvepointBackup::with('requestedBy')->find($this->backupId);
        if (! $backup || ! $backup->isDownloadable()) return;

        $email = $backup->requestedBy?->email;
        if (! $email) return;

        Mail::to($email)->send(new AvepointBackupReadyMail($backup));

        $backup->update(['requester_notified_at' => now()]);
    }
}
