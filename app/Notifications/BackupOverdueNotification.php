<?php

namespace App\Notifications;

use App\Models\BackupAccount;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to NotificationRule recipients when a device backup goes overdue.
 *
 * NOT ShouldQueue — production runs no queue worker, so notifications must send
 * synchronously inline (same as HostOfflineNotification / IspRenewalReminderNotification).
 */
class BackupOverdueNotification extends Notification
{
    public function __construct(
        public BackupAccount $account,
        public string $title,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[NOC] '.$this->title)
            ->greeting('Device backup overdue')
            ->line($this->message)
            ->line('Device: '.$this->account->deviceLabel())
            ->line('Account: '.$this->account->sftpgo_username)
            ->action('View backup account', url('/admin/backups/'.$this->account->id))
            ->line('You receive this because a notification rule routes "Device Backup Overdue" to you.');
    }
}
