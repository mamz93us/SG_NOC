<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Models\PrinterCounterSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SnapshotPrinterCountersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function handle(): void
    {
        $today = now()->toDateString();
        $written = 0;

        // Snapshot every printer that has reported a total page count at least once.
        // Includes both currently SNMP-enabled printers and ones whose monitoring is
        // temporarily off, so the historical record stays continuous.
        Printer::whereNotNull('page_count_total')
            ->orderBy('id')
            ->chunkById(200, function ($printers) use ($today, &$written) {
                foreach ($printers as $p) {
                    PrinterCounterSnapshot::updateOrCreate(
                        ['printer_id' => $p->id, 'snapshot_date' => $today],
                        [
                            'page_total' => $p->page_count_total,
                            'page_color' => $p->page_count_color,
                            'page_mono'  => $p->page_count_mono,
                            'page_copy'  => $p->page_count_copy,
                            'page_print' => $p->page_count_print,
                            'page_scan'  => $p->page_count_scan,
                            'page_fax'   => $p->page_count_fax,
                        ]
                    );
                    $written++;
                }
            });

        Log::info("SnapshotPrinterCountersJob: wrote {$written} snapshots for {$today}");
    }
}
