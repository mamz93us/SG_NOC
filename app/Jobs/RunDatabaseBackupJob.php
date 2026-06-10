<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Async path for the "Backup Now" button on Admin → Server Status. Lands on
 * the default DB queue and is picked up by the every-minute queue drainer
 * (no long-lived worker in production — see routes/console.php).
 */
class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $backupId) {}

    public function handle(DatabaseBackupService $service): void
    {
        $backup = DatabaseBackup::find($this->backupId);

        // Deleted, or already handled by an overlapping scheduled run.
        if (! $backup || ! $backup->isInFlight()) {
            return;
        }

        $service->run($backup);
    }

    public function failed(?\Throwable $e): void
    {
        // The service marks ordinary failures itself; this catches a job
        // killed before/around it (timeout, OOM) so no row is stuck "running".
        DatabaseBackup::where('id', $this->backupId)
            ->whereIn('status', [DatabaseBackup::STATUS_PENDING, DatabaseBackup::STATUS_RUNNING])
            ->update([
                'status' => DatabaseBackup::STATUS_FAILED,
                'error' => Str::limit('Backup job failed: '.($e?->getMessage() ?? 'unknown error'), 2000),
                'completed_at' => now(),
            ]);
    }
}
