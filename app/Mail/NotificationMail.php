<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Setting          $setting;
    public ?WorkflowRequest $workflow = null;

    public function __construct(
        public Notification $notification,
        public User         $recipient
    ) {
        $this->setting = Setting::first() ?? new Setting();

        // Auto-load the WorkflowRequest if the notification link points to one
        if ($notification->link && preg_match('#/workflows/(\d+)#', $notification->link, $m)) {
            $this->workflow = WorkflowRequest::with(['requester', 'branch', 'steps'])
                ->find((int) $m[1]);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                $this->setting->smtp_from_address ?? config('mail.from.address'),
                $this->setting->smtp_from_name    ?? config('mail.from.name'),
            ),
            subject: '[SG NOC] ' . $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
        );
    }
}
