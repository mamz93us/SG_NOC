<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces Azure-side retention for database dumps. No-ops unless
 * DB_BACKUP_RETENTION_DAYS is set — off-site backups never auto-delete
 * unless you opt in. History rows survive with status=pruned.
 */
class PruneDatabaseBackups extends Command
{
    protected $signature = 'db-backups:prune';

    protected $description = 'Delete database dumps from Azure Blob that are older than the retention window.';

    public function handle(): int
    {
        $days = config('db_backup.retention_days');
        if (! is_int($days) || $days <= 0) {
            $this->info('DB_BACKUP_RETENTION_DAYS not set — retention disabled, nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $pruned = $failed = 0;

        DatabaseBackup::liveInAzure()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (DatabaseBackup $b) use (&$pruned, &$failed) {
                try {
                    Storage::disk($b->disk ?: config('db_backup.disk', 'azure_db_backups'))->delete($b->azure_path);
                    $b->forceFill([
                        'status' => DatabaseBackup::STATUS_PRUNED,
                        'azure_path' => null,
                        'pruned_at' => now(),
                    ])->save();
                    $pruned++;
                } catch (\Throwable $e) {
                    Log::warning("db-backups:prune failed for backup {$b->id}: ".$e->getMessage());
                    $failed++;
                }
            });

        $this->info("Pruned {$pruned} dump(s) older than {$days} days".($failed ? ", {$failed} failed" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
