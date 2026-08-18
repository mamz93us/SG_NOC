<?php

namespace App\Jobs;

use App\Services\Notifications\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one notification over WhatsApp.
 *
 * Carries the alert as plain scalars rather than a Notification model: a rule
 * can also page on-call numbers that belong to no NOC user, and there is no
 * notification row behind those.
 *
 * Production runs no long-lived worker — the scheduler's `queue-drainer`
 * empties the DB queue every few minutes (routes/console.php), so this is
 * queued like the email job rather than sent inline.
 */
class SendNotificationWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    /** Back off between retries — a Meta rate-limit error clears on its own. */
    public array $backoff = [30, 120];

    /**
     * @param  array{type:string,title:string,message:string,link:?string,severity:string}  $alert
     */
    public function __construct(
        private string $toNumber,
        private array $alert,
        private ?int $notificationId = null,
        private ?string $toName = null,
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        if (! $whatsapp->isConfigured()) {
            return;
        }

        $whatsapp->sendAlert(
            to: $this->toNumber,
            fields: [
                'title' => $this->alert['title'] ?? '',
                'message' => $this->alert['message'] ?? '',
                'severity' => strtoupper((string) ($this->alert['severity'] ?? 'info')),
                'link' => $this->absoluteLink($this->alert['link'] ?? null),
                'time' => now()->format('Y-m-d H:i'),
            ],
            notificationId: $this->notificationId,
            notificationType: $this->alert['type'] ?? null,
            toName: $this->toName,
        );
    }

    /**
     * Notification links are stored app-relative ("/admin/network/..."), which
     * is useless in a chat message — make it clickable.
     */
    private function absoluteLink(?string $link): ?string
    {
        if (blank($link)) {
            return null;
        }

        return str_starts_with($link, 'http')
            ? $link
            : rtrim((string) config('app.url'), '/').'/'.ltrim($link, '/');
    }
}
