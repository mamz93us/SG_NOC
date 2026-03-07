<?php

namespace App\Notifications;

use App\Models\MonitoredHost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HostOfflineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $host;

    /**
     * Create a new notification instance.
     */
    public function __construct(MonitoredHost $host)
    {
        $this->host = $host;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $time = now()->format('Y-m-d H:i:s T');
        
        return (new MailMessage)
                    ->error() // Makes button/theme red by default in Laravel
                    ->subject("CRITICAL: Host Offline - {$this->host->name}")
                    ->greeting("Watchdog Alert!")
                    ->line("The following monitored device has gone completely offline and is unreachable via Ping packets.")
                    ->line("**Hostname:** {$this->host->name}")
                    ->line("**IP Address:** {$this->host->ip}")
                    ->line("**Device Type:** " . strtoupper($this->host->type))
                    ->line("**Last Successful Ping:** " . ($this->host->last_ping_at ? $this->host->last_ping_at->diffForHumans() : 'Never'))
                    ->line("**Failure Detected At:** {$time}")
                    ->action('View Device Details', url("/admin/network/monitoring/hosts/{$this->host->id}"))
                    ->line("Please investigate immediately!");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'host_id' => $this->host->id,
            'host_name' => $this->host->name,
            'status' => 'offline',
        ];
    }
}
