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
        $isp = $this->isp;
        $next = $isp->nextRenewalDate();

        if ($next) {
            $days = (int) $next->diffInDays(now(), false);
            $when = $next->isPast()
                ? 'OVERDUE (was '.$next->format('M d, Y').')'
                : $next->format('M d, Y').' ('.abs($days).' day'.(abs($days) !== 1 ? 's' : '').' away)';
        } else {
            $when = 'N/A';
        }

        $cost = $isp->monthly_cost ? number_format($isp->monthly_cost, 2) : 'N/A';
        $provider = $isp->ispProvider?->name ?? $isp->provider;
        $package = $isp->ispProviderPackage?->displayName() ?? $isp->package ?? 'N/A';

        $mail = (new MailMessage)
            ->subject("ISP Renewal Reminder: {$provider} — {$isp->branch?->name}")
            ->greeting('ISP Contract Renewal Reminder')
            ->line('The following ISP connection is due for renewal:')
            ->line("**Provider:** {$provider}")
            ->line('**Branch:** '.($isp->branch?->name ?: 'N/A'))
            ->line('**Account Number:** '.($isp->account_number ?: 'N/A'))
            ->line("**Package:** {$package}")
            ->line('**Connection Type:** '.($isp->connection_type ? strtoupper($isp->connection_type) : 'N/A'))
            ->line('**Customer Type:** '.($isp->customer_type ? ucfirst($isp->customer_type) : 'N/A'))
            ->line('**Payment Type:** '.($isp->payment_type ? ucfirst($isp->payment_type) : 'N/A'))
            ->line('**Billing Day:** '.($isp->billing_day ?: 'N/A'))
            ->line('**Speed:** '.$isp->speedLabel())
            ->line('**Circuit ID:** '.($isp->circuit_id ?: 'N/A'))
            ->line('**Static IP:** '.($isp->static_ip ?: 'N/A'))
            ->line("**Monthly Cost:** {$cost}")
            ->line("**Next Renewal:** {$when}");

        if ($isp->billing_day) {
            $upcoming = $isp->upcomingRenewals(4)->map(fn ($d) => $d->format('d M Y'))->implode(', ');
            $mail->line("**Upcoming cycles:** {$upcoming}");
        }

        $mail->action('View ISP Connections', url('/admin/network/isp'))
            ->line('Please take action to renew or terminate this contract before the renewal date.');

        if ($next && $next->isPast()) {
            $mail->error();
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'isp_id' => $this->isp->id,
            'provider' => $this->isp->ispProvider?->name ?? $this->isp->provider,
            'next_renewal' => $this->isp->nextRenewalDate()?->toDateString(),
        ];
    }
}
