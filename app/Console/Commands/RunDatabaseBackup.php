<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * Dumps the application database and ships it to Azure Blob. Scheduled daily
 * (see routes/console.php); also runnable by hand. The "Backup Now" button
 * goes through RunDatabaseBackupJob instead, but both end in
 * DatabaseBackupService::run().
 */
class RunDatabaseBackup extends Command
{
    protected $signature = 'db-backups:run
                            {--backup-id= : Process an existing pending database_backups row instead of creating one}';

    protected $description = 'mysqldump the application database, gzip it, and upload it to Azure Blob.';

    public function handle(DatabaseBackupService $service): int
    {
        if ($id = $this->option('backup-id')) {
            $backup = DatabaseBackup::find((int) $id);
            if (! $backup) {
                $this->error("No database_backups row with id {$id}.");

                return self::FAILURE;
            }
            if (! $backup->isInFlight()) {
                $this->warn("Backup {$id} is already '{$backup->status}'; nothing to do.");

                return self::SUCCESS;
            }
        } else {
            // Don't stack a scheduled run on top of a manual one already queued.
            if ($inFlight = DatabaseBackup::inFlight()->first()) {
                $this->warn("Backup {$inFlight->id} is already {$inFlight->status}; skipping this run.");

                return self::SUCCESS;
            }
            $backup = DatabaseBackup::create([
                'status' => DatabaseBackup::STATUS_PENDING,
                'triggered_via' => DatabaseBackup::VIA_SCHEDULED,
            ]);
        }

        try {
            $service->run($backup);
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup uploaded — [{$backup->disk}] {$backup->azure_path} ({$backup->humanSize()}).");

        return self::SUCCESS;
    }
}
