<?php

namespace App\Services\Printers;

use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\PollPrinterSnmpJob;
use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Services\AssetCodeService;
use App\Services\NetworkDiscoveryService;
use App\Services\PingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Printer-focused SNMP discovery.
 *
 * Wraps the generic NetworkDiscoveryService + PollPrinterSnmpJob so the
 * Printers UI can, in one click:
 *   1. ping + SNMP-discover + pull a single printer (on create / SNMP-enable)
 *   2. scan an IP range and auto-create + poll every printer it finds
 *
 * Everything runs synchronously — production has no dedicated queue worker
 * (see CLAUDE.md), so we never rely on dispatch() actually being drained.
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
    // Feature 2 — network scan: find printers and auto-import them
    // ─────────────────────────────────────────────────────────────

    /**
     * Scan an IP range for SNMP printers and auto-create + poll any that
     * aren't already in the system.
     *
     * @param  array{community?:?string,version?:?string,branch_id?:?int,timeout?:int}  $opts
     * @return array{
     *   scanned:int, reachable:int, printers:int, existing:int,
     *   created:array<int,array{id:int,name:string,ip:string,model:?string,toner:?int}>,
     *   truncated:bool, error:?string
     * }
     */
    public function scanForPrinters(string $range, array $opts = []): array
    {
        @set_time_limit(0);

        $community = ($opts['community'] ?? null) ?: 'public';
        $version = ($opts['version'] ?? null) ?: 'v2c';
        $branchId = $opts['branch_id'] ?? null;
        $timeout = (int) ($opts['timeout'] ?? 2);

        $summary = [
            'scanned' => 0,
            'reachable' => 0,
            'printers' => 0,
            'existing' => 0,
            'created' => [],
            'truncated' => false,
            'error' => null,
        ];

        $ips = $this->discovery->parseRange($range);

        if (empty($ips)) {
            $summary['error'] = 'No valid IPs parsed. Use CIDR (192.168.1.0/24) or a range (192.168.1.1-254).';

            return $summary;
        }

        // Bound the synchronous path. Larger sweeps belong in the full
        // Network Discovery tool (which runs as a background scan).
        $cap = 256;
        if (count($ips) > $cap) {
            $ips = array_slice($ips, 0, $cap);
            $summary['truncated'] = true;
        }
        $summary['scanned'] = count($ips);

        // Fast parallel liveness pass, then SNMP-probe only the live hosts.
        $alive = $this->fastPing($ips);
        $summary['reachable'] = count($alive);

        foreach ($alive as $ip) {
            try {
                $probe = $this->discovery->probeHost($ip, $community, $timeout);
            } catch (\Throwable $e) {
                Log::warning("PrinterDiscoveryService: probe failed for {$ip}: {$e->getMessage()}");

                continue;
            }

            if (! ($probe['snmp_accessible'] ?? false) || ($probe['device_type'] ?? null) !== 'printer') {
                continue;
            }

            $summary['printers']++;

            if (Printer::where('ip_address', $ip)->exists()) {
                $summary['existing']++;

                continue;
            }

            try {
                $printer = $this->createPrinterFromProbe($probe, $community, $version, $branchId);
            } catch (\Throwable $e) {
                Log::error("PrinterDiscoveryService: failed to create printer for {$ip}: {$e->getMessage()}");

                continue;
            }

            // Pull live data immediately so the new card isn't empty.
            try {
                $this->syncMonitoredHost($printer);
                PollPrinterSnmpJob::dispatchSync($printer->id);
                $printer->refresh();
            } catch (\Throwable $e) {
                Log::warning("PrinterDiscoveryService: first poll failed for {$ip}: {$e->getMessage()}");
            }

            $summary['created'][] = [
                'id' => $printer->id,
                'name' => $printer->printer_name,
                'ip' => $printer->ip_address,
                'model' => $printer->snmp_model ?: $printer->model,
                'toner' => $printer->lowestTonerLevel(),
            ];
        }

        return $summary;
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
     * Fast liveness check for a list of IPs. Uses fping -g in parallel when
     * available; falls back to a bounded serial ping otherwise.
     *
     * @param  array<int,string>  $ips
     * @return array<int,string> the subset that responded
     */
    protected function fastPing(array $ips): array
    {
        if (empty($ips)) {
            return [];
        }

        if (count($ips) === 1) {
            try {
                return ($this->ping->ping($ips[0], 1)['success'] ?? false) ? $ips : [];
            } catch (\Throwable) {
                return [];
            }
        }

        // Parallel sweep via fping (same approach as IpScannerController).
        try {
            $process = new Process(['fping', '-g', $ips[0], end($ips), '-a', '-r', '1', '-t', '200']);
            $process->setTimeout(90);
            $process->run();
            $output = $process->getOutput();
            if ($output !== '') {
                $alive = array_filter(array_map('trim', explode("\n", trim($output))));

                // fping -g walks a contiguous range; intersect to honour the exact list.
                return array_values(array_intersect($ips, $alive));
            }
            // fping ran but nothing answered → genuinely empty.
            if ($process->isSuccessful() || $process->getExitCode() === 1) {
                return [];
            }
        } catch (\Throwable $e) {
            Log::debug('PrinterDiscoveryService: fping unavailable, falling back to serial ping: '.$e->getMessage());
        }

        // Fallback: serial ping (bounded by the 256 cap upstream).
        $alive = [];
        foreach ($ips as $ip) {
            try {
                if ($this->ping->ping($ip, 1)['success'] ?? false) {
                    $alive[] = $ip;
                }
            } catch (\Throwable) {
                // skip
            }
        }

        return $alive;
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
