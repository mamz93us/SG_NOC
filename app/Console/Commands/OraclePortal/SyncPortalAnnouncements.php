<?php

namespace App\Console\Commands\OraclePortal;

use App\Models\OraclePortal\PortalSetting;
use App\Services\OraclePortal\AnnouncementSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies Oracle's announcements into the NOC's own table.
 *
 * Hourly: these are company notices, and the gap between HR posting one in
 * Oracle and it appearing on every company PC should be minutes, not a day.
 * The whole feed is 61 rows and 13 KB.
 */
class SyncPortalAnnouncements extends Command
{
    protected $signature = 'portal:sync-announcements
                            {--dry-run : Do the whole sync and roll it back, reporting what it would have done}';

    protected $description = 'Pull company announcements from the Samir Employee Portal API';

    public function handle(AnnouncementSync $sync): int
    {
        $settings = PortalSetting::get();

        if (! $settings->enabled || ! $settings->sync_announcements) {
            $this->comment('Oracle announcement sync is switched off in Settings — nothing to do.');

            return self::SUCCESS;
        }

        if ($issue = $settings->configurationIssue()) {
            $this->error($issue);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $counts = $sync->sync($dryRun, null, $settings);
        } catch (Throwable $e) {
            Log::error('Oracle announcement sync failed: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%d read: %d new, %d changed, %d unchanged, %d no longer in Oracle, %d back in Oracle.',
            $counts['rows'], $counts['created'], $counts['updated'],
            $counts['unchanged'], $counts['removed'], $counts['restored'],
        ));

        if ($counts['kept'] > 0) {
            $this->comment("{$counts['kept']} announcements have edits made here, which were kept.");
        }

        if ($dryRun) {
            $this->comment('Dry run — everything above was rolled back.');
        }

        return self::SUCCESS;
    }
}
