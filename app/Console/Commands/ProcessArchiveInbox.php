<?php

namespace App\Console\Commands;

use App\Services\Archive\InboxProcessor;
use Illuminate\Console\Command;

/**
 * Reads the scans waiting in the capture inbox, so the filing form is already
 * filled in when somebody opens it.
 *
 * Runs every minute and usually does nothing. The minute between a scan landing
 * and a person filing it is the whole opportunity: in it the pages are counted,
 * read, and turned into proposed field values, which is the difference between
 * checking an invoice number and transcribing one off an image.
 *
 * Nothing here decides anything — the suggestions are suggestions, and which
 * archive a scan belongs in is still answered by a person.
 */
class ProcessArchiveInbox extends Command
{
    protected $signature = 'archive:process-inbox
                            {--max-seconds=240 : Stop cleanly after this long}
                            {--max-items=25 : Items to read in one run}';

    protected $description = 'Read newly captured scans and pre-fill their filing details.';

    public function handle(InboxProcessor $processor): int
    {
        @set_time_limit(0);

        $deadline = microtime(true) + max(10, (int) $this->option('max-seconds'));

        $stats = $processor->run(
            max(1, (int) $this->option('max-items')),
            fn (): bool => microtime(true) >= $deadline,
        );

        if ($stats['items'] === 0) {
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d item(s), %d page(s) read, %d field(s) suggested%s%s',
            $stats['items'],
            $stats['read'],
            $stats['suggested'],
            $stats['failed'] ? ', '.$stats['failed'].' failed' : '',
            $stats['reason'] ? ' — '.$stats['reason'] : '',
        ));

        return self::SUCCESS;
    }
}
