<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrinterAlertMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'printers.alert';
    }

    private string $fromAddress;

    private string $fromName;

    private string $companyName;

    public function __construct(
        public NocEvent $event,
        public Printer $printer,
    ) {
        $setting = Setting::first();
        $this->fromAddress = $setting?->smtp_from_address ?: 'noreply@samirgroup.com';
        $this->fromName = $setting?->smtp_from_name ?: 'SG NOC';
        $this->companyName = $setting?->company_name ?: 'Samir Group';
    }

    public function envelope(): Envelope
    {
        $branch = $this->printer->branch?->name ?? '—';
        $sev = strtoupper($this->event->severity);

        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->templatedSubject("[SG-NOC] {$sev} — {$this->printer->printer_name} ({$branch}) — {$this->event->title}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }

    protected function templateWith(): array
    {
        return [
            'event' => $this->event,
            'printer' => $this->printer,
            'companyName' => $this->companyName,
            'fromName' => $this->fromName,
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ];
    }
}
