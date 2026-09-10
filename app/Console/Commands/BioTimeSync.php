<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use Illuminate\Console\Command;

/**
 * Pulls new punches from every enabled BioTime source.
 *
 * Sources are independent: one with a bad password or an unreachable server
 * records its error on its own row and the rest still sync.
 */
class BioTimeSync extends Command
{
    protected $signature = 'biotime:sync
        {--source= : Only this source id (runs even if the source is disabled)}
        {--since= : Re-read punches from this date (Y-m-d) instead of the watermark}
        {--max-rows=200000 : Stop after this many rows per source; the next run carries on}';

    protected $description = 'Copy new ZKTeco BioTime punches into attendance_punches and rebuild the affected days';

    public function handle(BioTimeSyncService $sync): int
    {
        $sources = $this->option('source')
            ? BiotimeSource::whereKey($this->option('source'))->get()
            : BiotimeSource::enabled()->orderBy('name')->get();

        if ($sources->isEmpty()) {
            $this->comment('No BioTime sources to sync — nothing to do.');

            return self::SUCCESS;
        }

        $since = $this->option('since');
        if ($since !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a date like 2026-09-01.');

            return self::FAILURE;
        }

        $maxRows = max(1, (int) $this->option('max-rows'));
        $failed = 0;

        foreach ($sources as $source) {
            try {
                $r = $sync->sync($source, $since, $maxRows);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$source->name}: ".BioTimeConnection::cleanError($e));

                continue;
            }

            if ($r['status'] === 'busy') {
                $this->warn("{$source->name}: another sync of this source is still running — skipped.");

                continue;
            }

            $this->info(sprintf(
                '%s: %d punch(es), %d new code(s), %d day(s) rebuilt, watermark %d%s%s',
                $source->name, $r['rows'], $r['new_codes'], $r['days'], $r['last_id'],
                $r['skipped'] ? ", {$r['skipped']} row(s) skipped (blank code or time)" : '',
                $r['done'] ? '' : ' — more to read, continues next run',
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
