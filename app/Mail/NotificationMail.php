<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public User $recipient
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
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
