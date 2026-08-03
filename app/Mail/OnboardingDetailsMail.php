<?php

namespace App\Mail;

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
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow,
        public string $recipientRole, // 'hr' | 'manager'
    ) {}

    public function envelope(): Envelope
    {
        $payload = $this->workflow->payload ?? [];
        $name = $payload['display_name'] ?? 'New employee';

        return new Envelope(subject: "{$name} is set up and ready");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.hr.onboarding_details');
    }
}
