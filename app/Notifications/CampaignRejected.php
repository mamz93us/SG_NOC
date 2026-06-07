<?php

namespace App\Notifications;

use App\Models\EmailMarketing\EmailCampaign;
use App\Support\Marketing;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the campaign creator when an approver rejects their campaign. The
 * campaign is returned to draft so they can edit and resubmit it.
 */
class CampaignRejected extends Notification
{
    public function __construct(public EmailCampaign $campaign, public string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->campaign;

        return (new MailMessage)
            ->subject("Campaign needs changes: {$c->name}")
            ->greeting('Your campaign was not approved')
            ->line("IT did not approve \"{$c->name}\".")
            ->line("**Reason:** {$this->reason}")
            ->line('It has been returned to draft so you can update and resubmit it.')
            ->action('Edit campaign', Marketing::url('/campaigns/'.$c->id.'/edit'))
            ->line('Thank you.');
    }
}
