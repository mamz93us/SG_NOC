<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\EmailLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Services\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    /**
     * @param  Notification  $notification  The in-app notification record
     * @param  User|null     $user          Override recipient (for rule-based routing)
     */
    public function __construct(
        private Notification $notification,
        private ?User        $user = null
    ) {}

    public function handle(SmtpConfigService $smtp): void
    {
        // Determine the recipient
        $recipient = $this->user ?? $this->notification->user;

        if (! $recipient || empty($recipient->email)) {
            return;
        }

        $smtp->loadFromSettings();

        // Read from-name directly from DB — belt-and-suspenders so the
        // sender name is correct regardless of config/env values.
        $setting  = Setting::first();
        $fromAddr = $setting?->smtp_from_address ?: config('mail.from.address');
        $fromName = $setting?->smtp_from_name    ?: 'SG NOC';

        $notification = $this->notification;
        $errorMessage = null;

        try {
            Mail::to($recipient->email, $recipient->name)
                ->send(
                    (new NotificationMail($this->notification, $recipient))
                        ->from($fromAddr, $fromName)  // explicit override — highest priority
                );
            $status = 'sent';
        } catch (\Throwable $e) {
            $status       = 'failed';
            $errorMessage = $e->getMessage();
        }

        // Write audit log
        try {
            EmailLog::create([
                'to_email'          => $recipient->email,
                'to_name'           => $recipient->name,
                'subject'           => "[SG NOC] {$notification->title}",
                'notification_type' => $notification->type,
                'notification_id'   => $notification->id,
                'status'            => $status,
                'error_message'     => $errorMessage,
                'sent_at'           => now(),
            ]);
        } catch (\Throwable) {
            // Don't fail the job if email log write fails
        }

        // Re-throw on failure so the job can retry
        if ($status === 'failed') {
            throw new \RuntimeException("Email send failed: {$errorMessage}");
        }
    }

}
