<?php

namespace App\Services\Printers;

use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\PollPrinterSnmpJob;
use App\Models\Device;
use App\Models\DiscoveryScan;
use App\Models\MonitoredHost;
use App\Models\Printer;
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
            PollPrinterSnmpJob::dispatchSync($printer->id);
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
