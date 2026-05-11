<?php

namespace App\Mail;

use App\Models\OffboardingBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackupDownloadReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OffboardingBackup $backup) {}

    public function envelope(): Envelope
    {
        $name = $this->backup->offboardingWorkflow?->employee?->name ?? 'Employee';
        $type = $this->backup->typeLabel();

        return new Envelope(
            subject: "Backup ready: {$type} for {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hr.backup_download_ready',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
