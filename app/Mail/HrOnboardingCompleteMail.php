<?php

namespace App\Mail;

use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HrOnboardingCompleteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow,
        public string          $recipientEmail
    ) {}

    public function envelope(): Envelope
    {
        $payload     = $this->workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'New Employee';
        $hrRef       = $payload['hr_reference'] ?? $this->workflow->id;

        return new Envelope(
            subject: "Onboarding Complete: {$displayName} (Ref: {$hrRef})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hr.onboarding_complete',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
