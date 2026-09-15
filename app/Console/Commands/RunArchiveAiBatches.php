<?php

namespace App\Console\Commands;

use App\Models\Archive\ArchiveAiBatch;
use App\Models\Archive\ArchiveAiSettings;
use App\Services\Archive\Ai\BatchRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Works the AI batches somebody started: reading history, or proposing values
 * for fields nobody ever filled in.
 *
 * Runs every minute and usually does nothing. When there is a batch it works a
 * slice of it and hands the budget back, so a job that takes days never holds a
 * request, never blocks another batch for long, and resumes cleanly after a
 * deploy — the same shape as every other worker in this app.
 *
 * The budget is the stop condition that matters. It is checked per document
 * inside the runner rather than once here, because a batch can run for hours
 * and the month's money can be spent by something else while it does.
 */
class RunArchiveAiBatches extends Command
{
    protected $signature = 'archive:ai-batch
                            {--max-seconds=240 : Stop cleanly after this long}
                            {--batch= : Work only this batch id}';

    protected $description = 'Work the document archive AI batches (read history, propose values for empty fields).';

    public function handle(BatchRunner $runner): int
    {
        @set_time_limit(0);

        $deadline = microtime(true) + max(10, (int) $this->option('max-seconds'));
        $shouldStop = fn (): bool => microtime(true) >= $deadline;

        $settings = ArchiveAiSettings::get();

        if (! $settings->withinBudget()) {
            // Silent when simply unconfigured: this runs every minute, and a
            // warning a minute about a feature nobody has switched on is how a
            // log stops being read.
            if ($settings->budget() > 0) {
                $this->warn('The month\'s archive AI budget has been spent.');
            }

            return self::SUCCESS;
        }

        $batches = ArchiveAiBatch::query()
            ->runnable()
            ->when($this->option('batch'), fn ($q, $id) => $q->whereKey($id))
            ->with('archive')
            ->orderBy('id')
            ->get();

        if ($batches->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($batches as $batch) {
            if ($shouldStop()) {
                $this->line('Time budget spent; the rest continues next run.');
                break;
            }

            try {
                $stats = $runner->run($batch, $shouldStop);

                $this->line(sprintf(
                    '#%d %s %-24s %d document(s), %d page(s), $%s%s',
                    $batch->getKey(),
                    $batch->type,
                    $batch->archive?->slug ?? '?',
                    $stats['documents'],
                    $stats['pages'],
                    number_format($stats['cost'], 4),
                    $stats['reason'] ? ' — '.$stats['reason'] : '',
                ));
            } catch (\Throwable $e) {
                $batch->finish(ArchiveAiBatch::STATUS_FAILED, $e->getMessage());
                $this->error('#'.$batch->getKey().': '.$e->getMessage());
                Log::error('[archive:ai-batch] #'.$batch->getKey().': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
