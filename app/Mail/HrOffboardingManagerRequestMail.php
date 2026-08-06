<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\OffboardingToken;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HrOffboardingManagerRequestMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'offboarding.manager_request';
    }

    public function __construct(
        public WorkflowRequest $workflow,
        public OffboardingToken $token,
        public bool $reminder = false,
    ) {}

    public function envelope(): Envelope
    {
        $payload = $this->workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'Employee';

        $prefix = $this->reminder ? 'REMINDER · ' : 'Action Required: ';

        return new Envelope(
            subject: $this->templatedSubject("{$prefix}Offboarding Confirmation for {$displayName}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }

    protected function templateWith(): array
    {
        return ['reminder' => $this->reminder];
    }

    public function attachments(): array
    {
        return [];
    }
}
