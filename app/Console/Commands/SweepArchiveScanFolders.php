<?php

namespace App\Console\Commands;

use App\Services\Archive\ScanFolderSweeper;
use Illuminate\Console\Command;

/**
 * Picks up the scans copiers wrote into their SFTP folders.
 *
 * Runs every minute and usually does nothing. A file is only taken once its mtime
 * has settled — an in-progress transfer keeps bumping it, and half a scan filed as
 * a document is worse than one that arrives a minute late — and the local copy is
 * deleted only after the write to Azure has been read back, because the original
 * is a sheet of paper already back in the tray.
 *
 * No-ops cleanly on any host without the scan root, which is every dev box.
 */
class SweepArchiveScanFolders extends Command
{
    protected $signature = 'archive:sweep-scan-folders
                            {--max-seconds=50 : Stop cleanly after this long}
                            {--max-files=50 : Files to take in one run}';

    protected $description = 'Take scans out of the SFTP scan folders and into the capture inbox.';

    public function handle(ScanFolderSweeper $sweeper): int
    {
        @set_time_limit(0);

        $deadline = microtime(true) + max(5, (int) $this->option('max-seconds'));

        $stats = $sweeper->run(
            max(1, (int) $this->option('max-files')),
            fn (): bool => microtime(true) >= $deadline,
        );

        if ($stats['files'] === 0 && $stats['failed'] === 0) {
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d scan(s) taken%s%s%s',
            $stats['files'],
            $stats['skipped'] ? ', '.$stats['skipped'].' not ready' : '',
            $stats['failed'] ? ', '.$stats['failed'].' failed' : '',
            $stats['reason'] ? ' — '.$stats['reason'] : '',
        ));

        return self::SUCCESS;
    }
}
