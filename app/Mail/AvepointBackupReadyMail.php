<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\AvepointBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AvepointBackupReadyMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function templateKey(): string
    {
        return 'backups.avepoint_ready';
    }

    public function __construct(public AvepointBackup $backup) {}

    public function envelope(): Envelope
    {
        $subject = $this->backup->subject_name ?? $this->backup->subject_upn;
        $type = $this->backup->typeLabel();

        return new Envelope(
            subject: $this->templatedSubject("AvePoint backup ready: {$type} for {$subject}"),
        );
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
