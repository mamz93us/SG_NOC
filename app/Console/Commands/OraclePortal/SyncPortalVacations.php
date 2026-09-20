<?php

namespace App\Console\Commands\OraclePortal;

use App\Models\OraclePortal\PortalSetting;
use App\Services\OraclePortal\VacationSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls Oracle's leave balances and records into the NOC.
 *
 * Daily: Oracle accrues leave monthly and the Balances page calls a figure out
 * of date after 35 days, so a nightly pull keeps every balance fresh without
 * the feed ever being the busy part of a minute. The whole run is three GETs
 * and about 1.2 MB.
 */
class SyncPortalVacations extends Command
{
    protected $signature = 'portal:sync-vacations
                            {--dry-run : Do the whole import and roll it back, reporting what it would have done}';

    protected $description = 'Pull leave balances and records from the Samir Employee Portal API';

    public function handle(VacationSync $sync): int
    {
        $settings = PortalSetting::get();

        if (! $settings->enabled || ! $settings->sync_vacations) {
            $this->comment('Oracle vacation sync is switched off in Settings — nothing to do.');

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
            // The WAF answers a blocked caller with an HTML body, so the
            // message is truncated by the client rather than filling the log.
            Log::error('Oracle vacation sync failed: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach (['balances', 'absences'] as $kind) {
            $this->line($result[$kind]->summary());

            foreach ($result[$kind]->notes ?? [] as $note) {
                $this->warn('  '.$note);
            }
        }

        if ($dryRun) {
            $this->comment('Dry run — everything above was rolled back.');
        }

        return self::SUCCESS;
    }
}
