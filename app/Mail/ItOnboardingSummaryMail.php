<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ItOnboardingSummaryMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'onboarding.it_summary';
    }

    public function __construct(
        public WorkflowRequest $workflow,
        public string $recipientEmail
    ) {}

    public function envelope(): Envelope
    {
        $displayName = $this->workflow->payload['display_name'] ?? 'New Employee';

        return new Envelope(
            subject: $this->templatedSubject("[SG NOC] IT Onboarding Summary: {$displayName}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
