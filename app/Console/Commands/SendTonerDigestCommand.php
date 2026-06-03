<?php

namespace App\Console\Commands;

use App\Services\Printers\PrinterTonerDigestService;
use Illuminate\Console\Command;

class SendTonerDigestCommand extends Command
{
    protected $signature = 'printers:toner-digest
        {--force : Send even if no printers are low and regardless of toner_email_mode}';

    protected $description = 'Send the consolidated monthly low-toner report (one email for all printers).';

    public function handle(PrinterTonerDigestService $digest): int
    {
        $force = (bool) $this->option('force');

        // Only auto-run in digest mode; --force overrides (manual test / on-demand).
        if (! $force && ! PrinterTonerDigestService::isDigestMode()) {
            $this->info('toner_email_mode is "immediate" — skipping. Use --force to send anyway.');

            return self::SUCCESS;
        }

        $result = $digest->send($force);

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
