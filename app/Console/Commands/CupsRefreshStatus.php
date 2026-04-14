<?php

namespace App\Console\Commands;

use App\Models\CupsPrinter;
use App\Services\CupsService;
use Illuminate\Console\Command;

class CupsRefreshStatus extends Command
{
    protected $signature = 'cups:refresh-status';

    protected $description = 'Refresh status of all active CUPS printers';

    public function handle(): int
    {
        $cups     = new CupsService();
        $printers = CupsPrinter::active()->get();

        if ($printers->isEmpty()) {
            $this->info('No active CUPS printers found.');
            return self::SUCCESS;
        }

        $this->info("Refreshing status for {$printers->count()} printer(s)...");

        $rows = [];

        foreach ($printers as $printer) {
            $status = $cups->getStatus($printer->queue_name);
            $printer->update(['status' => $status, 'last_checked_at' => now()]);
            $rows[] = [$printer->queue_name, $printer->ip_address, $status];
        }

        $this->table(['Queue', 'IP', 'Status'], $rows);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
