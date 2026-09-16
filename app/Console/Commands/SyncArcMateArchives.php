<?php

namespace App\Console\Commands;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveSource;
use App\Services\Archive\ArchiveSyncService;
use App\Services\Archive\ArcMate\ArcMateConnection;
use App\Services\Archive\ArcMate\ArcMateReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Copies ArcMate's index into the NOC, one budgeted slice per run.
 *
 * Driven by the scheduler rather than a queue worker, because production runs
 * no worker — the same arrangement as biotime:sync, which this follows closely.
 *
 * The budget matters more here than almost anywhere else in the app: SPS
 * Invoices alone is 513,381 documents and about 594,000 files, so the first
 * backfill is hours of work spread over many runs. Every pass is watermarked,
 * so a run that stops halfway simply resumes; nothing is ever re-read from the
 * beginning.
 *
 * Read-only against ArcMate throughout.
 */
class SyncArcMateArchives extends Command
{
    protected $signature = 'archive:sync-arcmate
                            {--archive= : Only this archive (id or slug)}
                            {--max-seconds=240 : Stop cleanly after this long}
                            {--batch=1000 : Rows per read}';

    protected $description = 'Copy new and changed documents from ArcMate into the document archive (read-only).';

    public function handle(): int
    {
        // A slice can outlast a request many times over; the scheduler runs us
        // in the background, so lift the CLI limit defensively.
        @set_time_limit(0);

        $deadline = microtime(true) + max(10, (int) $this->option('max-seconds'));
        $shouldStop = fn (): bool => microtime(true) >= $deadline;
        $batch = max(50, min(5000, (int) $this->option('batch')));

        if (! ArcMateConnection::driverAvailable()) {
            $this->warn('The PHP SQL Server driver (pdo_sqlsrv) is not installed — nothing to do.');

            return self::SUCCESS;
        }

        $source = ArchiveSource::query()->enabled()->first();

        if (! $source || ! $source->isConfigured()) {
            $this->info('No ArcMate source is configured yet; nothing to sync.');

            return self::SUCCESS;
        }

        $archives = $this->archives($source);

        if ($archives->isEmpty()) {
            $this->info('No archives are set to sync from ArcMate.');

            return self::SUCCESS;
        }

        $connector = new ArcMateConnection;
        $service = new ArchiveSyncService($source);
        $totals = ['documents' => 0, 'files' => 0, 'updated' => 0, 'deleted' => 0];
        $failures = 0;

        foreach ($archives as $archive) {
            if ($shouldStop()) {
                $this->info('Time budget spent; the rest continues next run.');
                break;
            }

            try {
                $reader = new ArcMateReader($connector->connection($source, (string) $archive->arcmate_database));
                $stats = $service->sync($archive, $reader, $batch, $shouldStop);

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $stats[$key];
                }

                $this->line(sprintf(
                    '%-24s +%d documents, +%d files, %d updated, %d deleted%s%s',
                    $archive->slug,
                    $stats['documents'],
                    $stats['files'],
                    $stats['updated'],
                    $stats['deleted'],
                    // Files whose document does not exist in ArcMate. Reported
                    // rather than dropped quietly: they are skipped on purpose,
                    // and a count that climbs is worth someone looking at.
                    ($stats['skipped'] ?? 0) ? ', '.$stats['skipped'].' skipped (no document)' : '',
                    $stats['caught_up'] ? ', caught up' : ''
                ));
            } catch (\Throwable $e) {
                $failures++;
                $message = ArcMateConnection::cleanError($e);
                $this->error($archive->slug.': '.$message);
                Log::error('[archive:sync-arcmate] '.$archive->slug.': '.$message);
            }
        }

        $source->forceFill([
            'last_sync_at' => now(),
            'last_sync_rows' => $totals['documents'] + $totals['files'],
            'consecutive_failures' => $failures > 0 ? $source->consecutive_failures + 1 : 0,
            'last_sync_error' => $failures > 0 ? 'One or more archives failed — see the log.' : null,
        ])->save();

        $this->info(sprintf(
            'Done: %d documents, %d files, %d updated, %d deleted.',
            $totals['documents'],
            $totals['files'],
            $totals['updated'],
            $totals['deleted']
        ));

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Archive> */
    private function archives(ArchiveSource $source)
    {
        $query = Archive::query()->syncable()->where('archive_source_id', $source->getKey());

        if ($only = trim((string) $this->option('archive'))) {
            $query->where(fn ($q) => $q->where('slug', $only)->orWhere('id', (int) $only));
        }

        // Least recently caught up first, so one enormous archive cannot starve
        // the others of the budget run after run.
        return $query->orderByRaw('CASE WHEN backfill_done_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
