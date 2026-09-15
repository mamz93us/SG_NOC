<?php

namespace App\Console\Commands;

use App\Models\Archive\ArchiveSource;
use App\Services\Archive\ArchiveTransferService;
use Illuminate\Console\Command;

/**
 * Copies ArcMate's files into the NOC's Azure storage, a slice at a time.
 *
 * Runs every minute and usually does nothing: outside the transfer window it
 * returns immediately. That is deliberate — a worker that wakes often and
 * decides quickly is far easier to reason about than one clever schedule, and
 * it means "Allow during working hours" on the Transfer page takes effect
 * within a minute rather than at the next cron boundary.
 *
 * 372 GB across 622,000 files, so this runs for nights. Every file is verified
 * before it is switched over, and nothing is ever deleted from the ArcMate
 * share — the copy is additive until somebody decides the old server can go.
 */
class TransferArchiveFiles extends Command
{
    protected $signature = 'archive:transfer-files
                            {--max-seconds=240 : Stop cleanly after this long}
                            {--max-files=500 : Stop after this many files}
                            {--now : Ignore the transfer window (for a supervised run)}';

    protected $description = 'Copy ArcMate files to Azure Blob, verifying each one before switching it over.';

    public function handle(ArchiveTransferService $transfers): int
    {
        @set_time_limit(0);

        $source = ArchiveSource::query()->first();

        if (! $source) {
            $this->info('No ArcMate source configured; nothing to transfer.');

            return self::SUCCESS;
        }

        // --now is for standing over it during a first run. It does not persist:
        // the window is a setting on the Transfer page, and a flag typed once
        // should not quietly change it for every run afterwards.
        if ($this->option('now')) {
            $source->transfer_anytime = true;
        }

        $deadline = microtime(true) + max(10, (int) $this->option('max-seconds'));

        $stats = $transfers->run(
            $source,
            max(1, (int) $this->option('max-files')),
            fn (): bool => microtime(true) >= $deadline,
        );

        if ($stats['files'] === 0 && $stats['failed'] === 0) {
            $this->line($stats['reason'] ?? 'Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Transferred %d file(s), %s, %d failed. %s',
            $stats['files'],
            $this->human($stats['bytes']),
            $stats['failed'],
            $stats['reason'] ?? '',
        ));

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1073741824
            ? number_format($bytes / 1073741824, 2).' GB'
            : number_format($bytes / 1048576, 1).' MB';
    }
}
