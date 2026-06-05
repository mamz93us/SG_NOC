<?php

namespace App\Console\Commands;

use App\Models\BackupAccount;
use App\Models\NocEvent;
use App\Models\Notification as AppNotification;
use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\BackupOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Raises (and resolves) a NocEvent per device backup account whose backup is
 * overdue — no successful archive within its expected frequency + grace. Mirrors
 * the check-host-ping / check-isp-renewals pattern in routes/console.php: open a
 * single NocEvent per account, fan out via NotificationRule on first open, and
 * resolve it once a fresh backup lands. Maintains BackupAccount.last_status so the
 * dashboard KPI + list reflect overdue state.
 */
class CheckOverdueBackups extends Command
{
    protected $signature = 'backups:check-overdue {--dry-run : Report without writing events/notifications}';

    protected $description = 'Open/resolve NocEvents for device backup accounts whose backup is overdue.';

    public function handle(): int
    {
        $accounts = BackupAccount::query()->active()
            ->where('expected_frequency', '!=', BackupAccount::FREQ_MANUAL)
            ->get();

        $opened = 0;
        $resolved = 0;

        foreach ($accounts as $account) {
            try {
                if ($account->isOverdue()) {
                    $opened += $this->openEvent($account) ? 1 : 0;
                } else {
                    $resolved += $this->resolveEvent($account) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                Log::error("backups:check-overdue failed for account #{$account->id}: ".$e->getMessage());
            }
        }

        $this->info("Overdue check — {$opened} opened, {$resolved} resolved, {$accounts->count()} checked.");

        return self::SUCCESS;
    }

    private function openEvent(BackupAccount $account): bool
    {
        $title = "Backup overdue: {$account->deviceLabel()}";
        $last = $account->lastBackupAt();
        $message = "No successful backup for '{$account->sftpgo_username}' "
            .($last ? "since {$last->diffForHumans()}" : 'yet')
            ." (expected {$account->expected_frequency}).";

        if ($this->option('dry-run')) {
            $this->line("  · would flag overdue: {$account->sftpgo_username}");

            return false;
        }

        $event = NocEvent::firstOrCreate(
            ['source_type' => 'backup_overdue', 'source_id' => $account->id, 'status' => 'open'],
            [
                'module' => 'network',
                'severity' => 'warning',
                'title' => $title,
                'message' => $message,
                'first_seen' => now(),
                'last_seen' => now(),
            ]
        );

        $account->forceFill(['last_status' => BackupAccount::STATUS_OVERDUE])->saveQuietly();

        if (! $event->wasRecentlyCreated) {
            $event->update(['last_seen' => now(), 'message' => $message]);

            return false;   // already open — don't re-notify
        }

        $this->notifyRecipients($account, $title, $message);

        return true;
    }

    private function resolveEvent(BackupAccount $account): bool
    {
        // Clear a stale "overdue" marker now that a fresh backup has landed.
        if ($account->last_status === BackupAccount::STATUS_OVERDUE) {
            $reset = $account->last_archived_at
                ? BackupAccount::STATUS_ARCHIVED
                : ($account->last_received_at ? BackupAccount::STATUS_RECEIVED : BackupAccount::STATUS_PENDING);
            $account->forceFill(['last_status' => $reset])->saveQuietly();
        }

        if ($this->option('dry-run')) {
            return false;
        }

        $count = NocEvent::where('source_type', 'backup_overdue')
            ->where('source_id', $account->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $count > 0;
    }

    private function notifyRecipients(BackupAccount $account, string $title, string $message): void
    {
        $recipients = collect();

        foreach (NotificationRule::active()->forEvent('backup_overdue')->get() as $rule) {
            if ($rule->recipient_type === 'user' && $rule->recipientUser) {
                $recipients->push($rule->recipientUser);
            } elseif ($rule->recipient_type === 'role' && $rule->recipient_role) {
                $recipients = $recipients->merge(User::role($rule->recipient_role)->get());
            }
        }

        $recipients->unique('id')->each(function (User $user) use ($account, $title, $message) {
            try {
                $user->notify(new BackupOverdueNotification($account, $title, $message));
            } catch (\Throwable $e) {
                Log::error("backup-overdue mail failed for user #{$user->id}: ".$e->getMessage());
            }
            try {
                AppNotification::create([
                    'user_id' => $user->id,
                    'type' => 'system_alert',
                    'severity' => 'warning',
                    'title' => $title,
                    'message' => $message,
                    'link' => '/admin/backups/'.$account->id,
                ]);
            } catch (\Throwable) {
            }
        });
    }
}
