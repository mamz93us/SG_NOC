<?php

namespace App\Services;

use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Models\PrinterCounterSnapshot;
use App\Models\SnmpSensor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mines historical printer page-counter readings out of the SNMP time-series
 * tables (sensor_metrics_daily) and writes them into printer_counter_snapshots
 * so the Usage Report can show period diffs going back as far as SNMP data
 * has been collected.
 *
 * Sensor naming comes from app/Polling/OS/RicohPrinterOS.php and GenericPrinterOS.php:
 *   Ricoh:    Total Counter / Print Counter / Fax Counter / Copy Counter /
 *             Color Pages / Mono Pages / Scan Counter
 *   Generic:  Page Count
 */
class PrinterSnapshotBackfillService
{
    /**
     * Map sensor name (case-insensitive) to the printer_counter_snapshots column.
     */
    private const COLUMN_MAP = [
        'page count'       => 'page_total',
        'total counter'    => 'page_total',
        'total pages'      => 'page_total',
        'print counter'    => 'page_print',
        'fax counter'      => 'page_fax',
        'copy counter'     => 'page_copy',
        'color pages'      => 'page_color',
        'color counter'    => 'page_color',
        'mono pages'       => 'page_mono',
        'mono counter'     => 'page_mono',
        'b/w pages'        => 'page_mono',
        'scan counter'     => 'page_scan',
        'scanner counter'  => 'page_scan',
    ];

    /**
     * Run the backfill across all printers (or filter to one).
     * @return array{filled:int, scanned:int, printers_with_data:int}
     */
    public function backfill(?int $printerId = null): array
    {
        $query = Printer::whereNotNull('ip_address');
        if ($printerId) {
            $query->where('id', $printerId);
        }
        $printers = $query->get();

        $filled = 0;
        $printersWithData = 0;

        foreach ($printers as $printer) {
            $rowsForThis = $this->backfillOne($printer);
            if ($rowsForThis > 0) {
                $printersWithData++;
                $filled += $rowsForThis;
            }
        }

        return [
            'filled' => $filled,
            'scanned' => $printers->count(),
            'printers_with_data' => $printersWithData,
        ];
    }

    /**
     * Backfill snapshots for a single printer. Returns rows written.
     */
    public function backfillOne(Printer $printer): int
    {
        $host = MonitoredHost::where('ip', $printer->ip_address)->first();
        if (! $host) {
            return 0;
        }

        // Find sensors on this host whose name maps to a snapshot column.
        $sensors = SnmpSensor::where('host_id', $host->id)->get();
        $sensorByColumn = []; // column => sensor_id

        foreach ($sensors as $sensor) {
            $key = strtolower(trim((string) $sensor->name));
            if (isset(self::COLUMN_MAP[$key])) {
                $col = self::COLUMN_MAP[$key];
                // First-match wins per column (order is by id; usually only one anyway).
                $sensorByColumn[$col] ??= $sensor->id;
            }
        }

        if (empty($sensorByColumn)) {
            return 0;
        }

        // Pull every daily rollup for these sensors. value_max per day is
        // the cumulative counter at end-of-day — exactly what we want.
        $sensorIds = array_values($sensorByColumn);
        $colBySensorId = array_flip($sensorByColumn);

        $rows = DB::table('sensor_metrics_daily')
            ->whereIn('sensor_id', $sensorIds)
            ->select('sensor_id', 'date', 'value_max')
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        // Pivot: date => [page_total => 1234, page_color => 567, ...]
        $perDate = [];
        foreach ($rows as $r) {
            $col = $colBySensorId[$r->sensor_id] ?? null;
            if (! $col) continue;
            $perDate[$r->date][$col] = (int) $r->value_max;
        }

        // upsert into printer_counter_snapshots
        $written = 0;
        foreach ($perDate as $date => $cols) {
            try {
                $existing = PrinterCounterSnapshot::where('printer_id', $printer->id)
                    ->where('snapshot_date', $date)
                    ->first();

                if ($existing) {
                    // Only fill in null columns — never overwrite values that
                    // came from a real end-of-day snapshot job run.
                    $changed = false;
                    foreach ($cols as $col => $val) {
                        if ($existing->{$col} === null) {
                            $existing->{$col} = $val;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $existing->save();
                        $written++;
                    }
                } else {
                    PrinterCounterSnapshot::create(array_merge([
                        'printer_id'    => $printer->id,
                        'snapshot_date' => $date,
                    ], $cols));
                    $written++;
                }
            } catch (\Throwable $e) {
                Log::warning("PrinterSnapshotBackfillService: failed to write {$printer->id} {$date}: {$e->getMessage()}");
            }
        }

        return $written;
    }
}
