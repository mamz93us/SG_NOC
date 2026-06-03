<?php

namespace App\Services\Printers;

use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\PollPrinterSnmpJob;
use App\Models\Device;
use App\Models\DiscoveryScan;
use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Models\PrinterSupply;
use App\Services\AssetCodeService;
use App\Services\NetworkDiscoveryService;
use App\Services\PingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Printer-focused SNMP discovery.
 *
 * Wraps the generic NetworkDiscoveryService + PollPrinterSnmpJob so the
 * Printers UI can:
 *   1. ping + SNMP-discover + pull a single printer (on create / SNMP-enable) — synchronous, bounded
 *   2. import the printer results of a completed network scan (auto-create + poll)
 *   3. discover SNMP sensors for printer hosts that don't have any yet
 *
 * Production has no dedicated queue worker (see CLAUDE.md), so bulk work (#2, #3)
 * is driven from scheduled tasks rather than dispatch(); buttons only enqueue a
 * DiscoveryScan or run a small bounded batch so the web request never times out.
 */
class PrinterDiscoveryService
{
    public function __construct(
        protected NetworkDiscoveryService $discovery,
        protected PingService $ping,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Feature 1 — single printer: ping → SNMP probe → pull
    // ─────────────────────────────────────────────────────────────

    /**
     * Ping, SNMP-discover and pull live data for one existing printer.
     * Safe to call inside the web request: bails fast when the host is
     * unreachable so an offline printer never stalls the form submit.
     *
     * @return array{reachable:bool,snmp:bool,polled:bool,message:string}
     */
    public function discoverAndPoll(Printer $printer): array
    {
        @set_time_limit(120);

        $summary = ['reachable' => false, 'snmp' => false, 'polled' => false, 'message' => ''];

        if (empty($printer->ip_address)) {
            $summary['message'] = 'No IP address set — SNMP discovery skipped.';

            return $summary;
        }

        // Mirror into MonitoredHost so it shows up in SNMP monitoring + ping checks.
        $this->syncMonitoredHost($printer);

        // 1. Ping (fast — single packet, 1s wait)
        try {
            $summary['reachable'] = (bool) ($this->ping->ping($printer->ip_address, 1)['success'] ?? false);
        } catch (\Throwable $e) {
            Log::debug("PrinterDiscoveryService: ping failed for {$printer->ip_address}: {$e->getMessage()}");
        }

        if (! $summary['reachable']) {
            $summary['message'] = "Printer saved, but {$printer->ip_address} did not answer a ping. "
                .'SNMP polling will retry automatically every 5 minutes.';

            return $summary;
        }

        // 2. Quick SNMP reachability check (sysDescr) before the heavier poll.
        $community = $printer->snmp_community ?: 'public';
        $sysDescr = $this->discovery->snmpGet($printer->ip_address, $community, '1.3.6.1.2.1.1.1.0', 2);
        $summary['snmp'] = $sysDescr !== null;

        if (! $summary['snmp']) {
            $summary['message'] = "{$printer->ip_address} is online but did not answer SNMP "
                .'(check the community string and that SNMP is enabled on the device). '
                .'Saved — polling will retry automatically.';

            return $summary;
        }

        // 3. Full poll: toner, supplies, counters, status, model, serial.
        try {
            PollPrinterSnmpJob::dispatchSync($printer->id, true);
            $printer->refresh();
            // Fill anything the direct poll missed from host-monitoring sensors.
            $this->backfillFromHostSensors($printer);
            $printer->refresh();
            $summary['polled'] = true;
            $summary['message'] = 'Printer discovered and polled — '.$this->pollSummary($printer).'.';
        } catch (\Throwable $e) {
            Log::error("PrinterDiscoveryService: poll failed for {$printer->ip_address}: {$e->getMessage()}");
            $summary['message'] = 'SNMP responded but polling failed: '.$e->getMessage();
        }

        return $summary;
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 2 — import printers from a completed network scan
    // ─────────────────────────────────────────────────────────────

    /**
     * Auto-create + poll every printer-type result of a finished DiscoveryScan
     * that isn't already in the system. Called by the scheduled scan processor
     * (NOT in a web request) so it can take as long as it needs.
     *
     * @return array{created:int, existing:int} counts
     */
    public function importScanResults(DiscoveryScan $scan): array
    {
        @set_time_limit(0);

        $counts = ['created' => 0, 'existing' => 0];

        $results = $scan->results()
            ->where('device_type', 'printer')
            ->where('already_imported', false)
            ->get();

        foreach ($results as $result) {
            if (Printer::where('ip_address', $result->ip_address)->exists()) {
                $result->update(['already_imported' => true]);
                $counts['existing']++;

                continue;
            }

            try {
                $printer = $this->createPrinterFromProbe([
                    'ip_address' => $result->ip_address,
                    'sys_name' => $result->sys_name,
                    'hostname' => $result->hostname,
                    'model' => $result->model,
                    'vendor' => $result->vendor,
                    'mac_address' => $result->mac_address,
                ], $scan->snmp_community ?: 'public', 'v2c', $scan->branch_id);
            } catch (\Throwable $e) {
                Log::error("PrinterDiscoveryService: import failed for {$result->ip_address}: {$e->getMessage()}");

                continue;
            }

            // Register for monitoring + pull live data so the card isn't empty.
            try {
                $this->syncMonitoredHost($printer);
                PollPrinterSnmpJob::dispatchSync($printer->id);
            } catch (\Throwable $e) {
                Log::warning("PrinterDiscoveryService: first poll failed for {$result->ip_address}: {$e->getMessage()}");
            }

            $result->update([
                'already_imported' => true,
                'imported_type' => 'printer',
                'imported_id' => $printer->id,
            ]);
            $counts['created']++;
        }

        if ($counts['created'] > 0) {
            $scan->increment('imported_count', $counts['created']);
        }

        return $counts;
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 3 — SNMP sensor discovery for printer hosts
    // ─────────────────────────────────────────────────────────────

    /**
     * Run SNMP sensor discovery for printer MonitoredHosts that have no sensors
     * yet (the gap left when DiscoverSnmpDeviceJob is dispatched but never drained
     * by a worker). Bounded by $limit so it's safe to call from a web request.
     *
     * Pass $onlyMissing=false to (re)discover sensors for every SNMP printer.
     *
     * @return array{processed:int, remaining:int}
     */
    public function discoverPrinterSensors(int $limit = 10, bool $onlyMissing = true): array
    {
        $query = MonitoredHost::where('snmp_enabled', true)
            ->where('type', 'printer')
            ->whereNotNull('ip');

        if ($onlyMissing) {
            $query->whereDoesntHave('snmpSensors');
        }

        $remaining = (clone $query)->count();
        $hosts = $query->orderBy('id')->limit($limit)->get();

        $processed = 0;
        foreach ($hosts as $host) {
            try {
                (new DiscoverSnmpDeviceJob($host))->handle();
                $processed++;
            } catch (\Throwable $e) {
                Log::error("PrinterDiscoveryService: sensor discovery failed for {$host->ip}: {$e->getMessage()}");
            }
        }

        return ['processed' => $processed, 'remaining' => max(0, $remaining - $processed)];
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 4 — sync printer fields from host-monitoring sensors
    // ─────────────────────────────────────────────────────────────

    /**
     * Backfill a printer's toner / page-counter / status fields from its
     * MonitoredHost SNMP sensors. The host's sensor pipeline (RicohPrinterOS,
     * etc.) reliably reads vendor MIBs that PollPrinterSnmpJob sometimes can't,
     * so this is how the SNMP page stays in sync with host monitoring.
     *
     * Fill-when-missing: only fields the printer's own poll left empty are
     * touched, so a printer that polls fine is never overwritten.
     */
    public function backfillFromHostSensors(Printer $printer): bool
    {
        if (empty($printer->ip_address)) {
            return false;
        }

        $host = MonitoredHost::with(['snmpSensors.latestMetric'])
            ->where('ip', $printer->ip_address)
            ->first();

        if (! $host || $host->snmpSensors->isEmpty()) {
            return false;
        }

        // First sensor whose name contains any needle → its latest value.
        $byName = function (array $needles) use ($host) {
            foreach ($host->snmpSensors as $s) {
                $name = strtolower($s->name);
                foreach ($needles as $n) {
                    if (str_contains($name, $n)) {
                        return $s->latestMetric?->value;
                    }
                }
            }

            return null;
        };

        $changed = false;
        $supplyIndex = 910; // synthetic, above real-MIB (1..N) and Ricoh fallback (900..)

        // ── Toner (reuse the host's own K/C/M/Y resolver) ──
        $toner = $host->tonerLevels();
        $tonerCols = [
            'toner_black' => ['v' => $toner['K'], 'color' => 'black'],
            'toner_cyan' => ['v' => $toner['C'], 'color' => 'cyan'],
            'toner_magenta' => ['v' => $toner['M'], 'color' => 'magenta'],
            'toner_yellow' => ['v' => $toner['Y'], 'color' => 'yellow'],
        ];
        foreach ($tonerCols as $col => $info) {
            if ($printer->$col !== null || $info['v'] === null) {
                continue; // printer already has it, or host has nothing
            }
            $pct = max(0, min(100, (int) round($info['v'])));
            $printer->$col = $pct;
            $this->upsertTonerSupply($printer, $info['color'], $pct, $supplyIndex++);
            $changed = true;
        }

        // ── Page counters ──
        $counters = [
            'page_count_total' => ['total counter', 'total pages', 'total'],
            'page_count_print' => ['print counter'],
            'page_count_copy' => ['copy counter', 'copy'],
            'page_count_fax' => ['fax counter', 'fax'],
            'page_count_color' => ['color pages', 'color counter', 'color'],
            'page_count_mono' => ['mono pages', 'mono', 'b/w'],
            'page_count_scan' => ['scan counter', 'scan'],
        ];
        foreach ($counters as $col => $needles) {
            if ($printer->$col !== null) {
                continue;
            }
            $v = $byName($needles);
            if ($v !== null && (int) round($v) >= 0) {
                $printer->$col = (int) round($v);
                $changed = true;
            }
        }

        // ── Printer status (3=idle, 4=printing, 5=warmup) ──
        if (empty($printer->printer_status) || $printer->printer_status === 'unknown') {
            $statusVal = $byName(['printer status']);
            if ($statusVal !== null) {
                $mapped = match ((int) round($statusVal)) {
                    3 => 'idle', 4 => 'printing', 5 => 'warmup', default => null,
                };
                if ($mapped) {
                    $printer->printer_status = $mapped;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $printer->snmp_last_polled_at = now();
            $printer->save();
        }

        return $changed;
    }

    /**
     * Backfill every SNMP printer from its host sensors. Returns the number of
     * printers that gained data. Safe for the scheduler (no time bound needed —
     * it only reads already-collected sensor rows, no live SNMP).
     */
    public function backfillAllFromHostSensors(): int
    {
        $count = 0;

        Printer::where('snmp_enabled', true)
            ->whereNotNull('ip_address')
            ->chunkById(100, function ($printers) use (&$count) {
                foreach ($printers as $printer) {
                    try {
                        if ($this->backfillFromHostSensors($printer)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning("PrinterDiscoveryService: host-sensor backfill failed for {$printer->ip_address}: {$e->getMessage()}");
                    }
                }
            });

        return $count;
    }

    /**
     * Mirror a toner percentage into PrinterSupply so the SNMP cards, dashboard
     * widgets and low-toner alerts (which read PrinterSupply) all reflect it.
     * Reuses an existing row for the colour to avoid duplicates.
     */
    protected function upsertTonerSupply(Printer $printer, string $color, int $pct, int $fallbackIndex): void
    {
        $existing = PrinterSupply::where('printer_id', $printer->id)
            ->where('supply_color', $color)
            ->where('supply_type', 'toner')
            ->first();

        PrinterSupply::updateOrCreate(
            ['printer_id' => $printer->id, 'supply_index' => $existing->supply_index ?? $fallbackIndex],
            [
                'supply_type' => 'toner',
                'supply_color' => $color,
                'supply_descr' => $existing->supply_descr ?? (ucfirst($color).' Toner'),
                'supply_percent' => $pct,
                'warning_threshold' => $existing->warning_threshold ?? 20,
                'critical_threshold' => $existing->critical_threshold ?? 5,
                'last_updated_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Create or update the MonitoredHost mirror for a printer so it appears
     * in the SNMP monitoring dashboard and gets pinged on schedule.
     *
     * Centralised here so PrinterController and the scanner stay in sync.
     */
    public function syncMonitoredHost(Printer $printer): void
    {
        if (empty($printer->ip_address)) {
            return;
        }

        $host = MonitoredHost::firstOrNew(['ip' => $printer->ip_address]);

        $host->fill([
            'name' => $printer->printer_name,
            'type' => 'printer',
            'snmp_enabled' => (bool) $printer->snmp_enabled,
            'snmp_version' => $printer->snmp_version ?: 'v2c',
            'snmp_community' => $printer->snmp_community ?: 'public', // accessor encrypts
            'snmp_port' => 161,
            'snmp_security_level' => 'noAuthNoPriv', // column is NOT NULL
            'ping_enabled' => true,
            'branch_id' => $printer->branch_id,
        ]);

        $isNew = ! $host->exists;
        $host->save();

        // Kick off sensor discovery for brand-new / newly-enabled hosts.
        if ($printer->snmp_enabled && ($isNew || $host->wasRecentlyCreated)) {
            DiscoverSnmpDeviceJob::dispatch($host);
        }
    }

    /**
     * Create a Device + Printer from a discovery probe result.
     */
    protected function createPrinterFromProbe(array $probe, string $community, string $version, ?int $branchId): Printer
    {
        $name = $probe['sys_name'] ?: ($probe['hostname'] ?: $probe['ip_address']);
        $assetCode = $this->generateAssetCode();

        return DB::transaction(function () use ($probe, $community, $version, $branchId, $name, $assetCode) {
            $device = Device::create([
                'type' => 'printer',
                'name' => $name,
                'model' => $probe['model'] ?? null,
                'mac_address' => $probe['mac_address'] ?? null,
                'ip_address' => $probe['ip_address'],
                'branch_id' => $branchId,
                'source' => 'printer',
                'source_id' => 'printer-'.Str::random(12),
                'status' => 'active',
                'asset_code' => $assetCode,
            ]);

            return Printer::create([
                'device_id' => $device->id,
                'printer_name' => $name,
                'ip_address' => $probe['ip_address'],
                'mac_address' => $probe['mac_address'] ?? null,
                'model' => $probe['model'] ?? null,
                'manufacturer' => $probe['vendor'] ?? null,
                'branch_id' => $branchId,
                'snmp_enabled' => true,
                'snmp_community' => $community,
                'snmp_version' => $version,
            ]);
        });
    }

    /**
     * Generate an asset code for a discovered printer, mirroring the
     * fallback PrinterController uses so a record is never left without one.
     */
    protected function generateAssetCode(): string
    {
        try {
            return (new AssetCodeService)->generate('printer');
        } catch (\Throwable $e) {
            Log::error('PrinterDiscoveryService: asset code generation failed: '.$e->getMessage());
            $lastCode = Device::where('asset_code', 'like', 'SG-PRN-%')
                ->orderByRaw('LENGTH(asset_code) DESC, asset_code DESC')
                ->value('asset_code');
            $seq = $lastCode ? ((int) ltrim(substr($lastCode, 7), '0') + 1) : 1;

            return 'SG-PRN-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        }
    }

    /** Short human summary of what a poll returned, for flash messages. */
    protected function pollSummary(Printer $printer): string
    {
        $bits = [];
        if ($printer->snmp_model) {
            $bits[] = 'model '.$printer->snmp_model;
        }
        $toner = $printer->lowestTonerLevel();
        if ($toner !== null) {
            $bits[] = "lowest toner {$toner}%";
        }
        if ($printer->page_count_total) {
            $bits[] = number_format($printer->page_count_total).' pages';
        }

        return $bits ? implode(', ', $bits) : 'no SNMP data returned yet';
    }
}
