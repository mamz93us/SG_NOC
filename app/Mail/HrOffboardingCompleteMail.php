<?php

namespace App\Mail;

use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HrOffboardingCompleteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow,
        public string          $recipientEmail
    ) {}

    public function envelope(): Envelope
    {
        $payload     = $this->workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'Employee';
        $hrRef       = $payload['hr_reference'] ?? $this->workflow->id;

        return new Envelope(
            subject: "Offboarding Complete: {$displayName} (Ref: {$hrRef})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hr.offboarding_complete',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
