<?php

namespace App\Mail;

use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow
    ) {}

    public function envelope(): Envelope
    {
        $displayName = $this->workflow->payload['display_name'] ?? 'there';
        return new Envelope(
            subject: "Welcome to Samir Group, {$displayName}!",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.employee.welcome');
    }
}
