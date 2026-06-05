<?php

namespace App\Console\Commands;

use App\Models\SftpBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces Azure-side retention for SFTP-inbox backups.
 *
 * Disabled by default: with sftp_backup.retention_days unset, this is a no-op —
 * off-site backups should never silently auto-delete unless an admin opts in by
 * setting SFTP_BACKUP_RETENTION_DAYS. When enabled, blobs older than the
 * retention window are deleted from Azure and the row is marked `pruned`
 * (azure_path cleared) so the audit trail survives.
 */
class PruneSftpBackups extends Command
{
    protected $signature = 'sftp-backups:prune
                            {--dry-run : Just print what would be deleted}';

    protected $description = 'Delete Azure Blob copies of SFTP-inbox backups older than the configured retention window.';

    public function handle(): int
    {
        $retentionDays = config('sftp_backup.retention_days');

        if ($retentionDays === null) {
            $this->info('Retention disabled (sftp_backup.retention_days unset) — keeping all backups.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $retentionDays);

        $candidates = SftpBackup::query()
            ->liveInAzure()
            ->whereNotNull('uploaded_at')
            ->where('uploaded_at', '<', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No backups older than {$retentionDays} day(s) to prune.");

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} blob(s) older than {$retentionDays} day(s).");

        $deleted = 0;
        foreach ($candidates as $b) {
            $this->line(" · #{$b->id} [{$b->disk}] {$b->azure_path} ({$b->humanSize()}, uploaded {$b->uploaded_at})");
            if ($this->option('dry-run')) {
                continue;
            }

            try {
                Storage::disk($b->disk)->delete($b->azure_path);
                $b->update([
                    'status' => SftpBackup::STATUS_PRUNED,
                    'azure_path' => null,
                    'pruned_at' => now(),
                ]);
                $deleted++;
            } catch (\Throwable $e) {
                $this->error("   failed: {$e->getMessage()}");
            }
        }

        $this->info("Pruned {$deleted} blob(s).");

        return self::SUCCESS;
    }
}
