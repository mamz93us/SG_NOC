<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AzureContactUpdateReminderMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'employee.contact_reminder';
    }

    public function __construct(
        public Employee $employee
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templatedSubject('Please update your mobile number in Outlook'),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }

    protected function templateWith(): array
    {
        return [
            'employeeName' => $this->employee->name,
        ];
    }
}
