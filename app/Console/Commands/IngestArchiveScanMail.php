<?php

namespace App\Console\Commands;

use App\Services\Archive\Mail\MailIngestService;
use Illuminate\Console\Command;

/**
 * Reads the scans the copiers mailed in.
 *
 * For the Ricoh MP C3001/C3003 units, which cannot do SFTP or FTPS at all — mail
 * is the only way they can send anything, so this is the difference between those
 * machines working and their users walking a USB stick to a PC.
 *
 * Postfix drops each message in the spool; this picks them up, files the
 * attachments into the right inbox by the token in the RECIPIENT (the sender is
 * rewritten by the relay and identifies nothing), and moves the message out of
 * `new/` whatever happened to it.
 *
 * No-ops cleanly when the spool does not exist, which is every dev box and the
 * NOC until deployment/archive-portal/scan-mail.sh has run.
 */
class IngestArchiveScanMail extends Command
{
    protected $signature = 'archive:ingest-mail
                            {--max-seconds=50 : Stop cleanly after this long}
                            {--max-messages=50 : Messages to read in one run}';

    protected $description = 'Read scans that arrived by e-mail into the capture inbox.';

    public function handle(MailIngestService $ingest): int
    {
        @set_time_limit(0);

        $deadline = microtime(true) + max(5, (int) $this->option('max-seconds'));

        $stats = $ingest->run(
            max(1, (int) $this->option('max-messages')),
            fn (): bool => microtime(true) >= $deadline,
        );

        if ($stats['messages'] === 0) {
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d message(s), %d scan(s) filed to inboxes%s%s%s',
            $stats['messages'],
            $stats['scans'],
            $stats['ignored'] ? ', '.$stats['ignored'].' ignored' : '',
            $stats['failed'] ? ', '.$stats['failed'].' failed' : '',
            $stats['reason'] ? ' — '.$stats['reason'] : '',
        ));

        return self::SUCCESS;
    }
}
