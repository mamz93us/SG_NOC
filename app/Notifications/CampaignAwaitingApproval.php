<?php

namespace App\Notifications;

use App\Models\EmailMarketing\EmailCampaign;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to approvers (super_admins) when a campaign with external recipients is
 * submitted and parked in `pending_approval`.
 */
class CampaignAwaitingApproval extends Notification
{
    public function __construct(public EmailCampaign $campaign, public array $summary = []) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->campaign;
        $domains = $this->summary['external_domains'] ?? [];

        $mail = (new MailMessage)
            ->subject("Campaign approval needed: {$c->name}")
            ->greeting('A campaign is awaiting approval')
            ->line('A marketing campaign has external recipients and needs IT approval before it can send.')
            ->line("**Campaign:** {$c->name}")
            ->line("**Subject:** {$c->subject}")
            ->line("**From:** {$c->from_email}")
            ->line('**Submitted by:** '.($c->creator->name ?? 'Unknown'))
            ->line('**Recipients:** '.($this->summary['total'] ?? '?').
                ' ('.($this->summary['external_count'] ?? '?').' external)');

        if ($domains !== []) {
            $mail->line('**External domains:** '.implode(', ', $domains));
        }

        return $mail
            ->action('Review & approve', url('/admin/email-marketing/approvals'))
            ->line('The campaign will not send until it is approved.');
    }
}
