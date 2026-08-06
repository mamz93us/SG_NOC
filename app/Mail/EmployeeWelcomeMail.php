<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'onboarding.employee_welcome';
    }

    public function __construct(
        public WorkflowRequest $workflow
    ) {}

    public function envelope(): Envelope
    {
        $displayName = $this->workflow->payload['display_name'] ?? 'there';

        return new Envelope(
            subject: $this->templatedSubject("Welcome to Samir Group, {$displayName}!"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
