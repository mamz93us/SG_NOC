<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDayProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rebuilds attendance_days: days with punches, and absences for people who
 * should have punched. biotime:sync already rebuilds the days it touches;
 * this is what records an absence (nobody punched, so nothing touched it),
 * and what applies a rule change to past days.
 */
class AttendanceProcess extends Command
{
    protected $signature = 'attendance:process
        {--days= : The last N days, including today (instead of --from/--to)}
        {--from= : First day (Y-m-d), default 7 days ago}
        {--to= : Last day (Y-m-d), default today}';

    protected $description = 'Rebuild check-in / check-out days and absences from stored BioTime punches';

    public function handle(AttendanceDayProcessor $processor): int
    {
        try {
            if ($this->option('days') !== null) {
                $to = CarbonImmutable::today();
                $from = $to->subDays(max(1, (int) $this->option('days')) - 1);
            } else {
                $to = CarbonImmutable::parse($this->option('to') ?? 'today');
                $from = CarbonImmutable::parse($this->option('from') ?? $to->subDays(6)->toDateString());
            }
        } catch (\Throwable) {
            $this->error('--from / --to must be dates like 2026-09-01.');

            return self::FAILURE;
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $count = $processor->rebuildRange($from->toDateString(), $to->toDateString());
        $this->info("Rebuilt {$count} day(s) from {$from->toDateString()} to {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
