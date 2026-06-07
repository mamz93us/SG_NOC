<?php

namespace App\Notifications;

use App\Models\EmailMarketing\EmailCampaign;
use App\Support\Marketing;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the campaign creator when an approver approves their campaign.
 */
class CampaignApproved extends Notification
{
    public function __construct(public EmailCampaign $campaign) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->campaign;

        return (new MailMessage)
            ->subject("Campaign approved: {$c->name}")
            ->greeting('Your campaign was approved')
            ->line("IT approved \"{$c->name}\". It is now queued and will start sending shortly.")
            ->action('View campaign', Marketing::url('/campaigns/'.$c->id))
            ->line('Thank you.');
    }
}
