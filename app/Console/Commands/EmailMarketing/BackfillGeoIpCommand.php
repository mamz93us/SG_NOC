<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailEvent;
use App\Services\EmailMarketing\GeoIpLookup;
use Illuminate\Console\Command;

/**
 * Walks email_events rows that have an ip_address but no country_code,
 * runs the GeoIpLookup against each unique IP, and updates the rows.
 *
 * Useful for events that landed BEFORE the geoip enrichment shipped.
 * Caches are reused, so repeats of the same IP cost one lookup total.
 */
class BackfillGeoIpCommand extends Command
{
    protected $signature = 'email-marketing:backfill-geoip
                            {--limit=1000 : Max events to process this run}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Look up country_code / country_name for email_events rows that have an IP but no country data yet.';

    public function handle(GeoIpLookup $geo): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        // Pull distinct IPs that need backfilling (cheap on the index)
        $ips = EmailEvent::query()
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->whereNull('country_code')
            ->distinct()
            ->limit($limit)
            ->pluck('ip_address')
            ->all();

        if (empty($ips)) {
            $this->info('Nothing to backfill — every event with an IP already has country data.');

            return Command::SUCCESS;
        }

        $this->info('Looking up '.count($ips).' distinct IP(s)…');
        $bar = $this->output->createProgressBar(count($ips));
        $bar->start();

        $resolved = 0;
        $skipped = 0;

        foreach ($ips as $ip) {
            $lookup = $geo->lookup($ip);
            if (empty($lookup['country_code']) && empty($lookup['country_name'])) {
                $skipped++;
                $bar->advance();
                continue;
            }

            if (! $dry) {
                EmailEvent::where('ip_address', $ip)
                    ->whereNull('country_code')
                    ->update([
                        'country_code' => $lookup['country_code'],
                        'country_name' => $lookup['country_name'],
                    ]);
            }
            $resolved++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(($dry ? '[dry-run] Would resolve ' : 'Resolved ').$resolved.' IP(s). '.$skipped.' returned no country data (private/unreachable).');

        return Command::SUCCESS;
    }
}
