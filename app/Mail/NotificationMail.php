<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?WorkflowRequest $workflow = null;

    // Scalar copies — avoids SerializesModels trying to reload a singleton model
    private string $fromAddress;
    private string $fromName;
    private string $companyName;
    private ?string $companyLogoPath;

    public function __construct(
        public Notification $notification,
        public User         $recipient
    ) {
        $setting = Setting::first();

        $this->fromAddress     = $setting?->smtp_from_address ?: 'noreply@samirgroup.com';
        $this->fromName        = $setting?->smtp_from_name    ?: 'SG NOC';
        $this->companyName     = $setting?->company_name      ?: 'Samir Group';
        $this->companyLogoPath = $setting?->company_logo      ?: null;

        // Auto-load WorkflowRequest when the notification links to one
        if ($notification->link && preg_match('#/workflows/(\d+)#', $notification->link, $m)) {
            $this->workflow = WorkflowRequest::with(['requester', 'branch', 'steps'])
                ->find((int) $m[1]);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from:    new Address($this->fromAddress, $this->fromName),
            subject: '[SG NOC] ' . $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'fromName'        => $this->fromName,
                'companyName'     => $this->companyName,
                'companyLogoPath' => $this->companyLogoPath,
            ],
        );
    }
}
