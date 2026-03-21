<?php

namespace App\Mail;

use App\Models\PrinterDeployToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PrinterSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PrinterDeployToken $token,
        public readonly Collection $printers,
        public readonly string $setupUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🖨️ Printer Setup Instructions — ' . ($this->token->branch?->name ?? 'Your Branch'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.printer_setup',
            with: [
                'token'     => $this->token,
                'printers'  => $this->printers,
                'setup_url' => $this->setupUrl,
            ],
        );
    }
}
