<?php

namespace App\Console\Commands;

use App\Services\PrinterSnapshotBackfillService;
use Illuminate\Console\Command;

class PrintersBackfillSnapshotsCommand extends Command
{
    protected $signature = 'printers:backfill-snapshots {--printer= : Backfill a single printer id}';
    protected $description = 'Backfill printer_counter_snapshots from historical sensor_metrics_daily rollups.';

    public function handle(PrinterSnapshotBackfillService $svc): int
    {
        $id = $this->option('printer');
        $stats = $svc->backfill($id ? (int) $id : null);

        $this->info(sprintf(
            'Backfill complete — wrote %d snapshot rows across %d printer(s) (out of %d scanned).',
            $stats['filled'],
            $stats['printers_with_data'],
            $stats['scanned']
        ));

        return self::SUCCESS;
    }
}
