<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\EmployeeLinker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Compares punches per day, BioTime vs the NOC, for each source.
 *
 * NOC short → with --fix, that day is re-read from BioTime.
 * NOC has extra → punches were deleted in BioTime. They are kept (raw punches
 * are never removed here) and reported, because a punch that vanishes from the
 * source is something HR should know about.
 *
 * Also retries auto-matching for codes that are still unmapped.
 */
class BioTimeReconcile extends Command
{
    protected $signature = 'biotime:reconcile
        {--source= : Only this source id}
        {--days=7 : How many days back, including today}
        {--fix : Re-read days where BioTime has more punches than the NOC}';

    protected $description = 'Check per-day punch counts against BioTime and repair gaps';

    public function handle(BioTimeSyncService $sync, EmployeeLinker $linker): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $from = CarbonImmutable::today()->subDays($days - 1)->toDateString();

        $sources = $this->option('source')
            ? BiotimeSource::whereKey($this->option('source'))->get()
            : BiotimeSource::enabled()->orderBy('name')->get();

        $problems = 0;

        foreach ($sources as $source) {
            $this->line("<info>{$source->name}</info> since {$from}");

            try {
                $remote = $sync->remoteDailyCounts($source, $from);
            } catch (\Throwable $e) {
                $problems++;
                $this->error('  '.BioTimeConnection::cleanError($e));

                continue;
            }

            $local = $sync->localDailyCounts($source, $from);

            if ($this->option('fix')) {
                foreach ($remote as $date => $count) {
                    if (($local[$date] ?? 0) < $count) {
                        $r = $sync->pullRange($source, $date, $date);
                        $this->line("  re-read {$date}: {$r['rows']} row(s), {$r['days']} day(s) rebuilt");
                    }
                }
                $local = $sync->localDailyCounts($source, $from);
            }

            $rows = [];
            $short = [];
            $extra = [];
            $dates = array_unique(array_merge(array_keys($remote), array_keys($local)));
            sort($dates);

            foreach ($dates as $date) {
                $r = $remote[$date] ?? 0;
                $l = $local[$date] ?? 0;
                $status = 'ok';
                if ($l < $r) {
                    $status = 'NOC short by '.($r - $l);
                    $short[] = $date;
                } elseif ($l > $r) {
                    $status = 'NOC has '.($l - $r).' more (deleted in BioTime?)';
                    $extra[] = $date;
                }
                $rows[] = [$date, $r, $l, $status];
            }

            $this->table(['Date', 'BioTime', 'NOC', 'Status'], $rows);

            if ($short || $extra) {
                $problems++;
                $sync->raiseEvent($source, 'biotime_reconcile',
                    "BioTime punch counts do not match for \"{$source->name}\"",
                    trim(($short ? 'NOC missing punches on '.implode(', ', $short).'. ' : '')
                        .($extra ? 'Punches deleted in BioTime on '.implode(', ', $extra).'.' : '')));
            } else {
                $sync->resolveEvent($source, 'biotime_reconcile');
            }

            $linked = $linker->retryUnlinked($source);
            if ($linked) {
                $this->line("  auto-linked {$linked} previously unmapped code(s)");
            }
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
