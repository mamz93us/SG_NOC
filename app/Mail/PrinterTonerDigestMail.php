<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrinterTonerDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    private string $fromAddress;

    private string $fromName;

    private string $companyName;

    /**
     * @param  array<int, array{branch:string, rows:array}>  $groups
     */
    public function __construct(
        public array $groups,
        public int $total,
        public string $period,
        public string $subjectLine,
    ) {
        $setting = Setting::first();
        $this->fromAddress = $setting?->smtp_from_address ?: 'noreply@samirgroup.com';
        $this->fromName = $setting?->smtp_from_name ?: 'SG NOC';
        $this->companyName = $setting?->company_name ?: 'Samir Group';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.printer-toner-digest',
            with: [
                'groups' => $this->groups,
                'total' => $this->total,
                'period' => $this->period,
                'companyName' => $this->companyName,
                'fromName' => $this->fromName,
                'appUrl' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
