<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "New starter is set up" notice for HR and the reporting manager.
 *
 * Deliberately NOT the IT summary: this one carries no credentials at all —
 * no initial password, no UCM extension secret, no personal mobile. Just the
 * contact details a colleague needs to reach the new person and route work to
 * them. See ItOnboardingSummaryMail for the IT-only version.
 */
class OnboardingDetailsMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'onboarding.details';
    }

    public function __construct(
        public WorkflowRequest $workflow,
        public string $recipientRole, // 'hr' | 'manager'
    ) {}

    public function envelope(): Envelope
    {
        $payload = $this->workflow->payload ?? [];
        $name = $payload['display_name'] ?? 'New employee';

        return new Envelope(subject: $this->templatedSubject("{$name} is set up and ready"));
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
