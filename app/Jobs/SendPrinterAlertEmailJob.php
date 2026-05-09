<?php

namespace App\Jobs;

use App\Mail\PrinterAlertMail;
use App\Models\EmailLog;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\PrinterAlertEmail;
use App\Models\PrinterBranchSetting;
use App\Services\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPrinterAlertEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = NocEvent::find($this->eventId);
        if (! $event) {
            Log::warning("SendPrinterAlertEmailJob: NocEvent {$this->eventId} not found");
            return;
        }

        // Idempotency: if the email has already been dispatched for this event, stop.
        if ($event->email_sent_at) {
            Log::debug("SendPrinterAlertEmailJob: skipping {$event->id} — already mailed at {$event->email_sent_at}");
            return;
        }

        if ($event->source_type !== 'printer' || ! $event->source_id) {
            Log::warning("SendPrinterAlertEmailJob: event {$event->id} is not a printer event");
            return;
        }

        $printer = Printer::with(['branch', 'device'])->find($event->source_id);
        if (! $printer) {
            Log::warning("SendPrinterAlertEmailJob: Printer {$event->source_id} not found for event {$event->id}");
            return;
        }

        // Resolve recipients
        $setting = $printer->branch_id
            ? PrinterBranchSetting::with(['activeRecipients.user'])->firstWhere('branch_id', $printer->branch_id)
            : null;

        if ($setting && ! $setting->alerts_enabled) {
            Log::info("SendPrinterAlertEmailJob: branch {$printer->branch_id} has alerts disabled, skipping");
            // Still stamp so we don't keep re-trying every poll
            $event->update(['email_sent_at' => now()]);
            return;
        }

        $to  = [];
        $cc  = [];

        if ($setting) {
            foreach ($setting->activeRecipients as $rec) {
                $email = $rec->effectiveEmail();
                if ($email) {
                    $to[$email] = $rec->effectiveName() ?? $email;
                }
            }
            if ($setting->manager_email) {
                $cc[$setting->manager_email] = $setting->manager_name ?? $setting->manager_email;
            }
        }

        // Fallback when no per-branch recipients are configured
        if (empty($to)) {
            $fallback = (array) config('printer_alerts.fallback_emails', []);
            foreach ($fallback as $email) {
                if ($email) $to[$email] = $email;
            }
        }

        if (empty($to)) {
            Log::warning("SendPrinterAlertEmailJob: no recipients for printer {$printer->id} (branch {$printer->branch_id}) — alert NOT emailed", [
                'event_id' => $event->id,
            ]);
            // Don't stamp email_sent_at — leave the event open for visibility, but record the failed attempt.
            PrinterAlertEmail::create([
                'noc_event_id' => $event->id,
                'printer_id'   => $printer->id,
                'to_emails'    => [],
                'cc_emails'    => [],
                'subject'      => "[SG-NOC] {$event->title}",
                'status'       => 'failed',
                'error'        => 'No recipients configured (no branch setting and no fallback emails).',
                'sent_at'      => now(),
            ]);
            return;
        }

        // Boot SMTP config from runtime Settings (matches SendNotificationEmailJob behavior)
        try {
            app(SmtpConfigService::class)->loadFromSettings();
        } catch (\Throwable $e) {
            Log::error("SendPrinterAlertEmailJob: SMTP config load failed: {$e->getMessage()}");
        }

        $subject = sprintf(
            '[SG-NOC] %s — %s (%s) — %s',
            strtoupper($event->severity),
            $printer->printer_name,
            $printer->branch?->name ?? '—',
            $event->title
        );

        $error  = null;
        $status = 'sent';

        try {
            $mailable = new PrinterAlertMail($event, $printer);

            $message = Mail::to(array_keys($to));
            if (! empty($cc)) {
                $message->cc(array_keys($cc));
            }
            $message->send($mailable);

            $event->update(['email_sent_at' => now()]);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();
            Log::error("SendPrinterAlertEmailJob: send failed for event {$event->id}: {$error}");
        }

        // Audit row
        try {
            PrinterAlertEmail::create([
                'noc_event_id' => $event->id,
                'printer_id'   => $printer->id,
                'to_emails'    => array_keys($to),
                'cc_emails'    => array_keys($cc),
                'subject'      => $subject,
                'status'       => $status,
                'error'        => $error,
                'sent_at'      => now(),
            ]);
        } catch (\Throwable) {
            // Don't fail the job for audit-table failures.
        }

        // Mirror into EmailLog so the global email-log dashboard sees it too.
        try {
            foreach (array_keys($to) as $addr) {
                EmailLog::create([
                    'to_email'          => $addr,
                    'to_name'           => $to[$addr] ?? null,
                    'subject'           => $subject,
                    'notification_type' => 'printer_alert',
                    'notification_id'   => null,
                    'status'            => $status,
                    'error_message'     => $error,
                    'sent_at'           => now(),
                ]);
            }
        } catch (\Throwable) {
        }

        if ($status === 'failed') {
            throw new \RuntimeException("Printer alert email failed: {$error}");
        }
    }
}
