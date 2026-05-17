<?php

namespace App\Mail\EmailMarketing;

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DoubleOptInMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $confirmUrl;

    public string $fromAddress;

    public string $fromName;

    public function __construct(public EmailSubscriber $subscriber, public EmailList $list, string $confirmUrl)
    {
        $this->confirmUrl = $confirmUrl;
        $setting = Setting::get();
        $this->fromAddress = $list->default_from_email
            ?: $setting->ses_default_from_email
            ?: $setting->smtp_from_address
            ?: 'noreply@samirgroup.com';
        $this->fromName = $list->default_from_name
            ?: $setting->ses_default_from_name
            ?: $setting->smtp_from_name
            ?: 'SG NOC';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: 'Confirm your subscription to '.$this->list->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marketing.double-opt-in',
        );
    }
}
