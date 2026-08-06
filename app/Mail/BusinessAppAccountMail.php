<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\BusinessApp;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks the team that runs a business system (Salesforce, Oracle, …) to create
 * or disable an account.
 *
 * NOC cannot touch these systems, so this email IS the handoff — if it does not
 * arrive, nothing happens. One mailable for both directions because the payload
 * is the same person and the same app; only the ask differs.
 *
 * Carries work details only. Never a password — NOC holds no credentials for
 * these systems.
 */
class BusinessAppAccountMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'business_app.account';
    }

    public const ACTIVATE = 'activate';

    public const DEACTIVATE = 'deactivate';

    public function __construct(
        public Employee $employee,
        public BusinessApp $app,
        public string $action = self::ACTIVATE,
        public ?WorkflowRequest $workflow = null,
        public ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->action === self::DEACTIVATE ? 'Disable' : 'Create';

        return new Envelope(
            subject: $this->templatedSubject("{$verb} {$this->app->name} account: {$this->employee->name}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
