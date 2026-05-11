<?php

namespace App\Console\Commands;

use App\Models\OffboardingBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daily cleanup of expired offboarding backup blobs.
 *
 * A blob is eligible for deletion when:
 *   - download_expires_at is more than 7 days in the past, AND
 *   - the parent offboarding workflow is 'completed', AND
 *   - the Azure user is already deleted (azure_deleted_at set).
 *
 * The OffboardingBackup row is kept (status=pruned, file_path/sha cleared)
 * for audit.
 */
class PruneOffboardingBackups extends Command
{
    protected $signature = 'offboarding:prune-expired-backups
                            {--dry-run : Just print what would be deleted}';

    protected $description = 'Delete Azure Blob copies of offboarding backups whose download window expired weeks ago.';

    public function handle(): int
    {
        $cutoff = now()->subDays(7);

        $candidates = OffboardingBackup::query()
            ->whereIn('status', ['completed'])
            ->whereNotNull('file_path')
            ->whereNotNull('download_expires_at')
            ->where('download_expires_at', '<', $cutoff)
            ->whereHas('offboardingWorkflow', function ($q) {
                $q->where('status', 'completed')->whereNotNull('azure_deleted_at');
            })
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No backups eligible for pruning.');
            return Command::SUCCESS;
        }

        $this->info("Found {$candidates->count()} blob(s) eligible for pruning.");

        $deleted = 0;
        foreach ($candidates as $b) {
            $this->line(" · backup #{$b->id} type={$b->type} path={$b->file_path}");
            if ($this->option('dry-run')) continue;

            try {
                Storage::disk('azure_offboarding')->delete($b->file_path);
                $b->update([
                    'status'              => 'pruned',
                    'file_path'           => null,
                    'download_token'      => null,
                    'download_expires_at' => null,
                ]);
                $deleted++;
            } catch (\Throwable $e) {
                $this->error("   failed: {$e->getMessage()}");
            }
        }

        $this->info("Pruned {$deleted} blob(s).");
        return Command::SUCCESS;
    }
}
