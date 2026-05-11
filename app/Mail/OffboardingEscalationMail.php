<?php

namespace App\Mail;

use App\Models\OffboardingWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffboardingEscalationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OffboardingWorkflow $offboardingWorkflow) {}

    public function envelope(): Envelope
    {
        $name = $this->offboardingWorkflow->employee?->name ?? 'employee';
        return new Envelope(
            subject: "ESCALATION · Offboarding requires IT action — {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.hr.offboarding_escalation');
    }

    public function attachments(): array
    {
        return [];
    }
}
