<?php

namespace App\Jobs;

use App\Mail\AzureContactUpdateReminderMail;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendMobileUpdateReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public int $employeeId) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $employee = Employee::with('identityUser')->find($this->employeeId);
        if (! $employee) {
            Log::warning("SendMobileUpdateReminderJob: employee #{$this->employeeId} not found.");
            return;
        }

        $toEmail = $employee->email ?: $employee->identityUser?->mail;
        $toName  = $employee->name;

        if (! $toEmail) {
            Log::info("SendMobileUpdateReminderJob: no email for employee #{$employee->id} — skipping.");
            return;
        }

        $smtp->loadFromSettings();

        $setting  = Setting::first();
        $fromAddr = $setting?->smtp_from_address ?: config('mail.from.address');
        $fromName = $setting?->smtp_from_name    ?: 'SG NOC';

        $subject      = 'Please update your mobile number in Outlook';
        $status       = 'sent';
        $errorMessage = null;

        try {
            Mail::to($toEmail, $toName)
                ->send(
                    (new AzureContactUpdateReminderMail($employee))
                        ->from($fromAddr, $fromName)
                );
            Log::info("SendMobileUpdateReminderJob: reminder sent to {$toEmail} for employee #{$employee->id}");
        } catch (\Throwable $e) {
            $status       = 'failed';
            $errorMessage = $e->getMessage();
            Log::error("SendMobileUpdateReminderJob: send failed to {$toEmail} for employee #{$employee->id}: {$errorMessage}");
        }

        try {
            EmailLog::create([
                'to_email'          => $toEmail,
                'to_name'           => $toName,
                'subject'           => $subject,
                'notification_type' => 'azure_contact_update_reminder',
                'notification_id'   => null,
                'status'            => $status,
                'error_message'     => $errorMessage,
                'sent_at'           => now(),
            ]);
        } catch (\Throwable) {
            // audit row failure must not break the job
        }

        if ($status === 'failed') {
            throw new \RuntimeException("Mobile update reminder failed: {$errorMessage}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("SendMobileUpdateReminderJob permanently failed for employee #{$this->employeeId}: "
            . $e->getMessage());
    }
}
