<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendancePeriod;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\AttendancePeriodService;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\EmployeeLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Does the attendance work the pages queued (AttendanceTask), oldest first.
 *
 * Production has no queue worker — the scheduler is the worker — so this runs
 * every minute. It stops taking new tasks after --max-seconds; one task that
 * fails is recorded and the next one still runs.
 */
class AttendanceWork extends Command
{
    protected $signature = 'attendance:work {--max-seconds=240 : Stop taking new tasks after this long}';

    protected $description = 'Run queued attendance tasks: syncs, recalculations and re-matching';

    /** A task marked running for longer than this was cut off (deploy, reboot, kill). */
    private const STALE_AFTER_MINUTES = 120;

    private const MAX_ATTEMPTS = 3;

    public function handle(BioTimeSyncService $sync, AttendanceDayProcessor $processor, EmployeeLinker $linker): int
    {
        $this->recoverStale();

        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));
        $ran = 0;

        while (microtime(true) < $deadline) {
            $task = AttendanceTask::where('status', AttendanceTask::PENDING)->orderBy('id')->first();
            if (! $task) {
                break;
            }

            // Claim it; a second worker that got here first wins.
            $claimed = AttendanceTask::whereKey($task->id)
                ->where('status', AttendanceTask::PENDING)
                ->update(['status' => AttendanceTask::RUNNING, 'started_at' => now(), 'attempts' => $task->attempts + 1]);
            if (! $claimed) {
                continue;
            }

            $task->refresh();
            $ran++;
            $this->line("#{$task->id} {$task->label}");

            // Shifts, holidays, links and approvals may have changed since the last task.
            $processor->forget();

            try {
                $result = $this->perform($task, $sync, $processor, $linker);
                $task->forceFill(['status' => AttendanceTask::DONE, 'result' => $result, 'finished_at' => now()])->save();
                $this->info('  '.$result);
            } catch (\Throwable $e) {
                $message = BioTimeConnection::cleanError($e);
                $task->forceFill(['status' => AttendanceTask::FAILED, 'result' => $message, 'finished_at' => now()])->save();
                Log::error("[attendance:work] #{$task->id} {$task->label}: {$message}");
                $this->error('  '.$message);
            }
        }

        if ($ran === 0) {
            $this->comment('Nothing queued.');
        }

        return self::SUCCESS;
    }

    private function perform(AttendanceTask $task, BioTimeSyncService $sync, AttendanceDayProcessor $processor, EmployeeLinker $linker): string
    {
        $payload = $task->payload ?? [];

        switch ($task->type) {
            case 'sync':
                $source = BiotimeSource::find($payload['source_id'] ?? 0)
                    ?? throw new \RuntimeException('That source no longer exists.');

                $r = $sync->sync($source, null, BioTimeSyncService::DEFAULT_MAX_ROWS, 200);

                return $r['status'] === 'busy'
                    ? 'Another sync of this source was already running; it carries on from there.'
                    : sprintf('%s row(s), %d new code(s), %d day(s) recalculated%s.', number_format($r['rows']), $r['new_codes'], $r['days'],
                        $r['done'] ? '' : ' — more to read, the scheduled sync continues');

            case 'rebuild':
                $days = $processor->rebuildRange($payload['from'], $payload['to'], $payload['employee_ids'] ?? null);

                return "{$days} day(s) recalculated.";

            case 'relink':
                $source = isset($payload['source_id']) ? BiotimeSource::find($payload['source_id']) : null;
                $linked = $linker->retryUnlinked($source);

                return $linked ? "{$linked} code(s) linked to an employee." : 'No new matches.';

            case 'export':
                $period = AttendancePeriod::find($payload['period_id'] ?? 0)
                    ?? throw new \RuntimeException('That period no longer exists.');
                $export = app(AttendancePeriodService::class)->export($period, $task->requested_by);

                return ucfirst($export->status).": {$export->record_count} record(s). {$export->message}";

            default:
                throw new \RuntimeException("Unknown task type \"{$task->type}\".");
        }
    }

    private function recoverStale(): void
    {
        AttendanceTask::where('status', AttendanceTask::RUNNING)
            ->where('started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get()
            ->each(function (AttendanceTask $task) {
                $retry = $task->attempts < self::MAX_ATTEMPTS;
                $task->forceFill([
                    'status' => $retry ? AttendanceTask::PENDING : AttendanceTask::FAILED,
                    'result' => $retry ? null : 'Stopped without finishing '.self::MAX_ATTEMPTS.' times.',
                    'finished_at' => $retry ? null : now(),
                ])->save();
            });
    }
}
