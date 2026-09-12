<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Retention for the audit trail.
 *
 * Now that every model write lands in activity_logs, the table grows without
 * bound — and it shares its database with the queue, the cache and the sessions,
 * so letting it grow is not free. Two windows, because two different things are
 * being kept:
 *
 *   - ordinary record edits: config('audit.retention_days')
 *   - security events (sign-ins, denials, permission changes):
 *     config('audit.security_retention_days'), longer, because these are what a
 *     privilege-escalation review actually reads and they are a small fraction
 *     of the rows.
 *
 * Deletes in bounded chunks: a single unqualified DELETE across a couple of
 * million rows holds locks long enough to stall the queue.
 */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity-log:prune
                            {--days= : Override the ordinary retention window}
                            {--dry-run : Report what would be deleted and stop}';

    protected $description = 'Delete audit rows past their retention window (security events kept longer)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 730));
        $securityDays = (int) config('audit.security_retention_days', 1825);
        $chunk = (int) config('audit.prune_chunk', 5000);
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        // A security window shorter than the ordinary one would mean sign-ins
        // and permission changes are dropped before routine edits — the opposite
        // of the intent, so treat the ordinary window as the floor.
        $securityDays = max($securityDays, $days);

        $securityActions = (array) config('audit.security_actions', []);

        $ordinaryCutoff = now()->subDays($days);
        $securityCutoff = now()->subDays($securityDays);

        $ordinary = ActivityLog::where('created_at', '<', $ordinaryCutoff)
            ->whereNotIn('action', $securityActions);

        $security = ActivityLog::where('created_at', '<', $securityCutoff)
            ->whereIn('action', $securityActions);

        $this->line("Ordinary events older than {$ordinaryCutoff->toDateString()} ({$days}d): ".$ordinary->clone()->count());
        $this->line("Security events older than {$securityCutoff->toDateString()} ({$securityDays}d): ".$security->clone()->count());

        if ($dryRun) {
            $this->info('Dry run — nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = $this->deleteInChunks($ordinary, $chunk)
            + $this->deleteInChunks($security, $chunk);

        $this->info("Pruned {$deleted} audit row(s).");

        // The prune itself is worth a row: a gap in the log should be
        // explainable without reading the scheduler's own logs.
        ActivityLog::create([
            'model_type' => 'System',
            'model_id' => 0,
            'action' => 'activity_log_pruned',
            'changes' => [
                'deleted' => $deleted,
                'retention_days' => $days,
                'security_retention_days' => $securityDays,
            ],
            'user_id' => null,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ActivityLog>  $query
     */
    private function deleteInChunks($query, int $chunk): int
    {
        $total = 0;

        do {
            $affected = $query->clone()->limit($chunk)->delete();
            $total += $affected;
        } while ($affected > 0);

        return $total;
    }
}
