<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Setting $setting;

    public function __construct(
        public Notification $notification,
        public User         $recipient
    ) {
        $this->setting = Setting::first() ?? new Setting();
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
