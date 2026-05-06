<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AzureContactUpdateReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Please update your mobile number in Outlook',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee.azure-contact-update-reminder',
            with: [
                'employeeName' => $this->employee->name,
            ],
        );
    }
}
