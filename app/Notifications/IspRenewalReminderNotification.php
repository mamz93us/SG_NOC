<?php

namespace App\Notifications;

use App\Models\IspConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IspRenewalReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public IspConnection $isp;

    public function __construct(IspConnection $isp)
    {
        $this->isp = $isp;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isp  = $this->isp;
        $days = $isp->renewal_date->diffInDays(now());
        $when = $isp->renewal_date->isPast()
            ? 'OVERDUE (was ' . $isp->renewal_date->format('M d, Y') . ')'
            : $isp->renewal_date->format('M d, Y') . " ({$days} day" . ($days !== 1 ? 's' : '') . ' away)';

        $mail = (new MailMessage)
            ->subject("ISP Renewal Reminder: {$isp->provider} — {$isp->branch?->name}")
            ->greeting('ISP Contract Renewal Reminder')
            ->line("The following ISP connection is due for renewal:")
            ->line("**Provider:** {$isp->provider}")
            ->line("**Branch:** " . ($isp->branch?->name ?: 'N/A'))
            ->line("**Circuit ID:** " . ($isp->circuit_id ?: 'N/A'))
            ->line("**Static IP:** " . ($isp->static_ip ?: 'N/A'))
            ->line("**Monthly Cost:** " . ($isp->monthly_cost ? number_format($isp->monthly_cost, 2) . ' SAR' : 'N/A'))
            ->line("**Renewal Date:** {$when}")
            ->action('View ISP Connections', url('/admin/network/isp'))
            ->line('Please take action to renew or terminate this contract before the renewal date.');

        if ($isp->renewal_date->isPast()) {
            $mail->error();
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'isp_id'       => $this->isp->id,
            'provider'     => $this->isp->provider,
            'renewal_date' => $this->isp->renewal_date->toDateString(),
        ];
    }
}
