<?php

namespace App\Mail;

use App\Models\PrinterDeployToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrinterSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PrinterDeployToken $token) {}

    public function envelope(): Envelope
    {
        $config      = $this->token->printer_config ?? [];
        $printerName = $config['printer_name'] ?? 'Printer';

        return new Envelope(
            subject: "Printer Setup Instructions: {$printerName}",
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
