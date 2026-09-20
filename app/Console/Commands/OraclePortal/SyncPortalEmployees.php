<?php

namespace App\Console\Commands\OraclePortal;

use App\Models\OraclePortal\PortalSetting;
use App\Services\OraclePortal\EmployeeSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stages Oracle's employee view for review on the HR Import page.
 *
 * Daily rather than hourly: what this produces is a queue somebody works
 * through, not a live feed, and HR data moves on a human timescale. A run that
 * finds Oracle unchanged creates no batch at all.
 *
 * It never terminates anybody. See EmployeeSync for why that is not a
 * threshold to tune but the wrong question — the feed is the Saudi book, so
 * every SSS Egypt employee is permanently missing from it.
 */
class SyncPortalEmployees extends Command
{
    protected $signature = 'portal:sync-employees
                            {--dry-run : Stage the batch and roll it back, reporting what it would have done}';

    protected $description = 'Stage Oracle employee records for review from the Samir Employee Portal API';

    public function handle(EmployeeSync $sync): int
    {
        $settings = PortalSetting::get();

        if (! $settings->enabled || ! $settings->sync_employees) {
            $this->comment('Oracle employee sync is switched off in Settings — nothing to do.');

            return self::SUCCESS;
        }

        if ($issue = $settings->configurationIssue()) {
            $this->error($issue);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $sync->sync($dryRun, null, $settings);
        } catch (Throwable $e) {
            Log::error('Oracle employee sync failed: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['unchanged']) {
            $this->info("Oracle is unchanged since the last pull ({$result['rows']} people) — no batch created.");

            return self::SUCCESS;
        }

        $batch = $result['batch'];

        $this->line(sprintf(
            '%d read: %d matched, %d need a decision, %d with a problem.',
            $result['rows'], $batch?->matched_count ?? 0, $batch?->unmatched_count ?? 0, $batch?->error_count ?? 0,
        ));

        if ($result['leavers_refused']) {
            $this->warn(sprintf(
                'Oracle called %d matched people inactive, too many to be believable — no statuses were recorded.',
                $result['inactive'],
            ));
        } elseif ($result['inactive'] > 0) {
            $this->warn(sprintf(
                '%d people are inactive in Oracle but still employed here. They are listed for review on '
                .'the HR Import page; nothing was terminated.',
                $result['inactive'],
            ));
        }

        $this->comment('Nothing was written to employee records beyond what Oracle says about their '
            .'assignment. Apply the batch from Admin → Identity → HR Import.');

        if ($dryRun) {
            $this->comment('Dry run — everything above was rolled back.');
        }

        return self::SUCCESS;
    }
}
