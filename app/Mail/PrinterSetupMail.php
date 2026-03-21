<?php

namespace App\Mail;

use App\Models\PrinterDeployToken;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrinterSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $employeeName;
    public string $branchName;
    public string $setupUrl;

    public function __construct(
        public PrinterDeployToken $token,
        public Collection         $printers,
        string                    $setupUrl
    ) {
        $this->employeeName = $token->employee?->name ?? $token->sent_to_email;
        $this->branchName   = $token->branch?->name   ?? 'Your Branch';
        $this->setupUrl     = $setupUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🖨️ Printer Setup Instructions — {$this->branchName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.printer_setup',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
