<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\OffboardingWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffboardingEscalationMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'offboarding.escalation';
    }

    public function __construct(public OffboardingWorkflow $offboardingWorkflow) {}

    public function envelope(): Envelope
    {
        $name = $this->offboardingWorkflow->employee?->name ?? 'employee';

        return new Envelope(
            subject: $this->templatedSubject("ESCALATION · Offboarding requires IT action — {$name}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }

    public function attachments(): array
    {
        return [];
    }
}
