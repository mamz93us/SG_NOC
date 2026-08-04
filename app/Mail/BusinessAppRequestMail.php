<?php

namespace App\Mail;

use App\Models\BusinessApp;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Please create an account in your system for this new starter."
 *
 * Sent to whoever administers the app (Salesforce, Oracle, …). Contains the
 * work details they need to identify the person — never a password, since NOC
 * holds no credentials for these systems.
 */
class BusinessAppRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkflowRequest $workflow,
        public BusinessApp $app,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->workflow->payload['display_name'] ?? 'New employee';

        return new Envelope(subject: "Account request: {$this->app->name} for {$name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.hr.business_app_request');
    }
}
