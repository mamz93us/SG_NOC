<?php

namespace App\Mail;

use App\Models\OffboardingToken;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HrOffboardingManagerRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest  $workflow,
        public OffboardingToken $token
    ) {}

    public function envelope(): Envelope
    {
        $payload     = $this->workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'Employee';

        return new Envelope(
            subject: "Action Required: Offboarding Confirmation for {$displayName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hr.offboarding_manager_request',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
