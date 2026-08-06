<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\PrinterDeployToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PrinterSetupMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'printers.setup';
    }

    public function __construct(
        public readonly PrinterDeployToken $token,
        public readonly Collection $printers,
        public readonly string $setupUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templatedSubject('🖨️ Printer Setup Instructions — '.($this->token->branch?->name ?? 'Your Branch')),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }

    protected function templateWith(): array
    {
        return [
            'token' => $this->token,
            'printers' => $this->printers,
            'setup_url' => $this->setupUrl,
        ];
    }
}
