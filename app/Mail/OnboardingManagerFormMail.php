<?php

namespace App\Mail;

use App\Models\OnboardingManagerToken;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingManagerFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest        $workflow,
        public OnboardingManagerToken $token
    ) {}

    public function envelope(): Envelope
    {
        $payload     = $this->workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'New Employee';

        return new Envelope(
            subject: "Action Required: New Employee Setup Form for {$displayName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hr.onboarding_manager_request',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
