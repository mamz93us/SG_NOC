<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\Notification;
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

        $notification = $this->notification;
        $errorMessage = null;

        try {
            Mail::html($this->buildHtml($notification), function ($message) use ($recipient, $notification) {
                $message->to($recipient->email, $recipient->name)
                        ->subject("[SG NOC] {$notification->title}");
            });

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

    private function buildHtml(Notification $n): string
    {
        $appName = config('app.name', 'SG NOC');
        $link    = $n->link ? "<p><a href=\"{$n->link}\" style=\"color:#0d6efd\">View Details →</a></p>" : '';
        $color   = match ($n->severity) {
            'critical' => '#dc3545',
            'warning'  => '#ffc107',
            default    => '#0dcaf0',
        };

        return "
        <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
            <div style='background:{$color};padding:16px;border-radius:4px 4px 0 0'>
                <h2 style='color:#fff;margin:0'>{$appName}</h2>
            </div>
            <div style='padding:24px;background:#fff;border:1px solid #dee2e6'>
                <h3 style='margin-top:0'>{$n->title}</h3>
                <p style='color:#495057'>{$n->message}</p>
                {$link}
                <hr style='border-color:#dee2e6'>
                <p style='color:#6c757d;font-size:12px'>
                    This notification was sent by {$appName}.
                    You can manage your notification preferences in your account settings.
                </p>
            </div>
        </div>";
    }
}
