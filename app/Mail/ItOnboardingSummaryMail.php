<?php

namespace App\Mail;

use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ItOnboardingSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow,
        public string          $recipientEmail
    ) {}

    public function envelope(): Envelope
    {
        $displayName = $this->workflow->payload['display_name'] ?? 'New Employee';
        return new Envelope(
            subject: "[SG NOC] IT Onboarding Summary: {$displayName}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.it.onboarding_summary');
    }
}
