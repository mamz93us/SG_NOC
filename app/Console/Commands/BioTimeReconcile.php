<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\EmployeeLinker;
use App\Services\Attendance\PunchMirror;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Compares the NOC's punches with the source database, row by row, and with
 * --fix makes the NOC match again.
 *
 * biotime:sync only reads forwards, so anything the source changes to a punch
 * it has already handed over never reaches the NOC on its own: an edited time
 * keeps driving the old day's check-in, a deleted scan keeps counting. This is
 * the pass that closes that — see Services\Attendance\PunchMirror for what it
 * will and will not touch (approved periods are left alone; a suspiciously
 * large number of removals is reported rather than applied).
 *
 * It used to compare punch COUNTS per day, which cannot see an edit at all —
 * the count is the same on both sides — and deliberately kept punches deleted
 * at the source.
 *
 * Also retries auto-matching for codes that are still unmapped.
 */
class BioTimeReconcile extends Command
{
    protected $signature = 'biotime:reconcile
        {--source= : Only this source id}
        {--days=7 : How many days back, including today}
        {--fix : Apply the differences — write new and changed punches, stamp removed ones}
        {--allow-bulk-delete : Stamp removals even when there are too many for an unattended run}
        {--max-seconds=0 : Give up reading a source after this long (0 = no limit)}';

    protected $description = 'Compare punches against the source database and bring the NOC back in step';

    public function handle(PunchMirror $mirror, BioTimeSyncService $sync, EmployeeLinker $linker): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $from = CarbonImmutable::today()->subDays($days - 1)->toDateString();
        $to = CarbonImmutable::today()->toDateString();
        $maxSeconds = max(0, (int) $this->option('max-seconds')) ?: null;

        $sources = $this->option('source')
            ? BiotimeSource::whereKey($this->option('source'))->get()
            : BiotimeSource::enabled()->orderBy('name')->get();

        $problems = 0;

        foreach ($sources as $source) {
            $this->line("<info>{$source->name}</info> {$from} → {$to}");

            try {
                $result = $mirror->run($source, $from, $to, $this->option('fix'),
                    (bool) $this->option('allow-bulk-delete'), $maxSeconds);
            } catch (\Throwable $e) {
                $problems++;
                $this->error('  '.BioTimeConnection::cleanError($e));

                continue;
            }

            if ($result['status'] === 'busy') {
                $this->comment('  A sync of this source is running; skipped.');

                continue;
            }

            $this->report($result);
            $problems += $this->raise($source, $sync, $result) ? 1 : 0;

            $linked = $linker->retryUnlinked($source);
            if ($linked) {
                $this->line("  auto-linked {$linked} previously unmapped code(s)");
            }
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, mixed>  $result */
    private function report(array $result): void
    {
        $rows = [];

        foreach ($result['by_date'] as $date => $counts) {
            $rows[] = [$date, $counts['source'], $counts['noc'], match (true) {
                $counts['noc'] < $counts['source'] => 'NOC short by '.($counts['source'] - $counts['noc']),
                $counts['noc'] > $counts['source'] => 'NOC has '.($counts['noc'] - $counts['source']).' more',
                default => 'ok',
            }];
        }

        $this->table(['Date', 'Source', 'NOC (before)', 'Count'], $rows);

        $verb = $result['applied'] ? ['written', 'rewritten', 'stamped removed'] : ['to write', 'to rewrite', 'to remove'];
        $this->line(sprintf('  %d %s, %d %s, %d %s.',
            $result['added'], $verb[0], $result['changed'], $verb[1], $result['removed'], $verb[2]));

        if ($result['days']) {
            $this->line("  {$result['days']} day(s) recalculated.");
        }

        if ($result['locked']) {
            $this->warn("  {$result['locked']} punch(es) left alone — their day is in an approved period. Reopen it to take these.");
        }

        if (! $result['done']) {
            $this->warn('  The source was not read to the end within the time budget.');
        }

        if ($result['refused'] !== null) {
            $this->warn('  Removals not applied: '.$result['refused'].'.');
            foreach ($result['removed_sample'] as $punch) {
                $this->line("    {$punch}");
            }
        }
    }

    /**
     * One open event per source while anything is out of step or held back,
     * resolved as soon as a run finds nothing to say.
     *
     * @param  array<string, mixed>  $result
     * @return bool whether this source counts as a problem
     */
    private function raise(BiotimeSource $source, BioTimeSyncService $sync, array $result): bool
    {
        $notes = [];

        if ($result['refused'] !== null) {
            $notes[] = 'Punches missing at the source were NOT removed: '.$result['refused'].'.';
        }

        if ($result['locked']) {
            $notes[] = $result['locked'].' punch(es) differ from the source inside an approved period and were left alone.';
        }

        if (! $result['applied'] && ($result['added'] || $result['changed'] || $result['removed'])) {
            $notes[] = sprintf('%d new, %d changed and %d deleted punch(es) at the source are not reflected here.',
                $result['added'], $result['changed'], $result['removed']);
        }

        if ($notes === []) {
            $sync->resolveEvent($source, 'biotime_reconcile');

            return false;
        }

        $sync->raiseEvent($source, 'biotime_reconcile',
            "Attendance punches do not match the source for \"{$source->name}\"",
            implode(' ', $notes));

        return true;
    }
}
