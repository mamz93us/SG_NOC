<?php

namespace App\Jobs;

use App\Models\NocEvent;
use App\Models\Printer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PollPrinterSnmpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(public ?int $printerId = null)
    {
    }

    public function handle(): void
    {
        $query = Printer::where('snmp_enabled', true)
            ->whereNotNull('ip_address')
            ->whereNotNull('snmp_community');

        if ($this->printerId) {
            $query->where('id', $this->printerId);
        }

        $printers = $query->get();

        if ($printers->isEmpty()) {
            Log::debug('PollPrinterSnmpJob: No SNMP-enabled printers found.');
            return;
        }

        foreach ($printers as $printer) {
            try {
                // Redis cache lock to avoid re-polling too soon
                $lockKey = "printer_snmp_lock_{$printer->id}";
                try {
                    if (Cache::store('redis')->has($lockKey)) {
                        Log::debug("PollPrinterSnmpJob: Skipping {$printer->ip_address} — recently polled.");
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Redis down — continue polling anyway
                }

                $this->pollPrinter($printer);

                // Set lock for 4 minutes after successful poll
                try {
                    Cache::store('redis')->put($lockKey, true, 240);
                } catch (\Throwable $e) {
                    // Redis down — ignore
                }
            } catch (\Throwable $e) {
                Log::error("PollPrinterSnmpJob: Error polling {$printer->ip_address}: {$e->getMessage()}");
            }
        }
    }

    protected function pollPrinter(Printer $printer): void
    {
        $ip        = $printer->ip_address;
        $community = $printer->snmp_community;
        $version   = $this->snmpVersion($printer->snmp_version);
        $port      = 161;
        $timeout   = 1500000; // 1.5s
        $retries   = 1;

        Log::info("PollPrinterSnmpJob: Polling {$ip}");

        // ─── System Info ─────────────────────────────────────────
        $sysDescr = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.1.0', $version, $port);
        $sysModel = $this->snmpGet($ip, $community, '1.3.6.1.2.1.25.3.2.1.3.1', $version, $port);
        $sysSerial = $this->snmpGet($ip, $community, '1.3.6.1.2.1.43.5.1.1.17.1', $version, $port);

        if ($sysDescr !== null) $printer->snmp_sys_description = $this->cleanValue($sysDescr);
        if ($sysModel !== null) $printer->snmp_model = $this->cleanValue($sysModel);
        if ($sysSerial !== null) $printer->snmp_serial = $this->cleanValue($sysSerial);

        // ─── Printer Status ──────────────────────────────────────
        $hrStatus = $this->snmpGetInt($ip, $community, '1.3.6.1.2.1.25.3.5.1.1.1', $version, $port);
        if ($hrStatus !== null) {
            $printer->printer_status = match ($hrStatus) {
                3 => 'idle',
                4 => 'printing',
                5 => 'warmup',
                default => 'unknown',
            };
        }

        // ─── Error State (bitmask) ───────────────────────────────
        $errorState = $this->snmpGet($ip, $community, '1.3.6.1.2.1.25.3.5.1.2.1', $version, $port);
        $printer->error_state = $this->parseErrorState($errorState);

        // ─── Toner Levels ────────────────────────────────────────
        // Try Ricoh Private MIB first (more reliable for Ricoh)
        $isRicoh = $this->isRicoh($sysDescr ?? '', $printer->manufacturer ?? '');
        $tonerData = $isRicoh
            ? $this->pollRicohToner($ip, $community, $version, $port)
            : $this->pollStandardToner($ip, $community, $version, $port);

        $printer->toner_black   = $tonerData['black'] ?? $printer->toner_black;
        $printer->toner_cyan    = $tonerData['cyan'] ?? $printer->toner_cyan;
        $printer->toner_magenta = $tonerData['magenta'] ?? $printer->toner_magenta;
        $printer->toner_yellow  = $tonerData['yellow'] ?? $printer->toner_yellow;
        $printer->toner_waste   = $tonerData['waste'] ?? $printer->toner_waste;

        // ─── Drum / Fuser Levels ─────────────────────────────────
        $supplies = $this->pollSupplyLevels($ip, $community, $version, $port);
        $printer->drum_black  = $supplies['drum_black'] ?? $printer->drum_black;
        $printer->drum_color  = $supplies['drum_color'] ?? $printer->drum_color;
        $printer->fuser_level = $supplies['fuser'] ?? $printer->fuser_level;

        // ─── Paper Trays ─────────────────────────────────────────
        $trays = $this->pollPaperTrays($ip, $community, $version, $port);
        if (!empty($trays)) {
            $printer->paper_trays = $trays;
        }

        // ─── Page Counters ───────────────────────────────────────
        if ($isRicoh) {
            $this->pollRicohCounters($ip, $community, $version, $port, $printer);
        }

        // Standard page count (total)
        $totalPages = $this->snmpGetInt($ip, $community, '1.3.6.1.2.1.43.10.2.1.4.1.1', $version, $port);
        if ($totalPages !== null && $totalPages > 0) {
            $printer->page_count_total = $totalPages;
        }

        $printer->snmp_last_polled_at = now();
        $printer->save();

        // ─── Normalized Supplies Table ───────────────────────────────
        $this->syncSupplies($printer, $ip, $community, $version, $port);

        // ─── Threshold Alerts ────────────────────────────────────
        $this->checkAlerts($printer);

        Log::info("PollPrinterSnmpJob: Completed {$ip}", [
            'toner_black' => $printer->toner_black,
            'toner_cyan'  => $printer->toner_cyan,
            'status'      => $printer->printer_status,
            'pages'       => $printer->page_count_total,
        ]);
    }

    // ─── Ricoh Private MIB Toner ──────────────────────────────────

    protected function pollRicohToner(string $ip, string $community, int $version, int $port): array
    {
        $data = [];

        // Ricoh Private MIB: ricohEngTonerLevel
        // 1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.{index}
        // Walk the toner name tree to find correct mapping
        $names = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.24.1.1.3', $version, $port);
        $levels = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5', $version, $port);

        if (!$names || !$levels) {
            // Fallback to standard MIB
            return $this->pollStandardToner($ip, $community, $version, $port);
        }

        // Map names to levels
        foreach ($names as $nameOid => $nameVal) {
            $nameClean = strtolower($this->cleanValue($nameVal));
            // Extract index (last number in OID)
            $index = $this->lastOidIndex($nameOid);
            $level = null;

            foreach ($levels as $levelOid => $levelVal) {
                if ($this->lastOidIndex($levelOid) == $index) {
                    $level = $this->parseIntValue($levelVal);
                    break;
                }
            }

            if ($level === null) continue;

            // Ricoh special toner values:
            //   -3 = some supply remaining (unknown qty) → keep as-is, shown as 1% by -3 check above
            //   -100 (or any < -3) = "Cartridge Almost Empty" alert → map to 0%, NOT abs()
            //   Positive values are direct percentages 0–100
            if ($level < -3) $level = 0;
            if ($level > 100) $level = 100;

            if (str_contains($nameClean, 'black') || str_contains($nameClean, 'bk')) {
                $data['black'] = $level;
            } elseif (str_contains($nameClean, 'cyan') || str_contains($nameClean, 'c ')) {
                $data['cyan'] = $level;
            } elseif (str_contains($nameClean, 'magenta') || str_contains($nameClean, 'm ')) {
                $data['magenta'] = $level;
            } elseif (str_contains($nameClean, 'yellow') || str_contains($nameClean, 'y ')) {
                $data['yellow'] = $level;
            } elseif (str_contains($nameClean, 'waste')) {
                $data['waste'] = $level;
            }
        }

        return $data;
    }

    // ─── Standard Printer MIB Toner (RFC 3805) ───────────────────

    protected function pollStandardToner(string $ip, string $community, int $version, int $port): array
    {
        $data = [];

        // prtMarkerSuppliesDescription: .1.3.6.1.2.1.43.11.1.1.6
        // prtMarkerSuppliesMaxCapacity:  .1.3.6.1.2.1.43.11.1.1.8
        // prtMarkerSuppliesLevel:        .1.3.6.1.2.1.43.11.1.1.9
        $descriptions = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.6', $version, $port);
        $maxCapacities = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.8', $version, $port);
        $currentLevels = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.9', $version, $port);

        if (!$descriptions || !$currentLevels) {
            return $data;
        }

        foreach ($descriptions as $descOid => $descVal) {
            $desc = strtolower($this->cleanValue($descVal));
            $index = $this->lastTwoOidIndexes($descOid);

            $level = $this->findByIndex($currentLevels, $index);
            $max = $this->findByIndex($maxCapacities, $index);

            $levelInt = $this->parseIntValue($level);
            $maxInt = $this->parseIntValue($max);

            if ($levelInt === null) continue;

            // Handle special values
            if ($levelInt == -3) {
                $pct = 1; // some supply remaining
            } elseif ($levelInt == -2 || $levelInt == -1) {
                continue; // unknown
            } elseif ($maxInt && $maxInt > 0) {
                $pct = (int) round(($levelInt / $maxInt) * 100);
            } else {
                $pct = $levelInt; // assume already percentage
            }

            $pct = max(0, min(100, $pct));

            // Map description to color
            if ($this->descContainsToner($desc, 'black')) {
                $data['black'] = $pct;
            } elseif ($this->descContainsToner($desc, 'cyan')) {
                $data['cyan'] = $pct;
            } elseif ($this->descContainsToner($desc, 'magenta')) {
                $data['magenta'] = $pct;
            } elseif ($this->descContainsToner($desc, 'yellow')) {
                $data['yellow'] = $pct;
            } elseif (str_contains($desc, 'waste')) {
                $data['waste'] = $pct;
            }
        }

        return $data;
    }

    // ─── Supply Levels (Drum, Fuser, Maintenance Kit) ─────────────

    protected function pollSupplyLevels(string $ip, string $community, int $version, int $port): array
    {
        $data = [];

        $descriptions = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.6', $version, $port);
        $maxCapacities = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.8', $version, $port);
        $currentLevels = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.9', $version, $port);

        if (!$descriptions || !$currentLevels) return $data;

        foreach ($descriptions as $descOid => $descVal) {
            $desc = strtolower($this->cleanValue($descVal));
            $index = $this->lastTwoOidIndexes($descOid);

            $level = $this->findByIndex($currentLevels, $index);
            $max = $this->findByIndex($maxCapacities, $index);

            $levelInt = $this->parseIntValue($level);
            $maxInt = $this->parseIntValue($max);

            if ($levelInt === null || $levelInt < 0) continue;

            $pct = ($maxInt && $maxInt > 0) ? (int) round(($levelInt / $maxInt) * 100) : $levelInt;
            $pct = max(0, min(100, $pct));

            if (str_contains($desc, 'drum') || str_contains($desc, 'photoconductor')) {
                if (str_contains($desc, 'black') || str_contains($desc, 'bk') || !str_contains($desc, 'color')) {
                    $data['drum_black'] = $data['drum_black'] ?? $pct;
                } else {
                    $data['drum_color'] = $data['drum_color'] ?? $pct;
                }
            } elseif (str_contains($desc, 'fuser')) {
                $data['fuser'] = $pct;
            }
        }

        return $data;
    }

    // ─── Paper Trays ─────────────────────────────────────────────

    protected function pollPaperTrays(string $ip, string $community, int $version, int $port): array
    {
        $trays = [];

        // prtInputName:         .1.3.6.1.2.1.43.8.2.1.13
        // prtInputMaxCapacity:  .1.3.6.1.2.1.43.8.2.1.9
        // prtInputCurrentLevel: .1.3.6.1.2.1.43.8.2.1.10
        $names = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.8.2.1.13', $version, $port);
        $maxCaps = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.8.2.1.9', $version, $port);
        $curLevels = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.8.2.1.10', $version, $port);

        if (!$names) return $trays;

        $i = 0;
        foreach ($names as $nameOid => $nameVal) {
            $index = $this->lastTwoOidIndexes($nameOid);
            $name = $this->cleanValue($nameVal) ?: ('Tray ' . (++$i));

            $max = $this->parseIntValue($this->findByIndex($maxCaps, $index));
            $current = $this->parseIntValue($this->findByIndex($curLevels, $index));

            // Skip bypass/manual feed trays with -2 (unknown) capacity
            if ($max !== null && $max <= 0) continue;

            $trays[] = [
                'name'    => $name,
                'current' => $current ?? 0,
                'max'     => $max ?? 0,
            ];
        }

        return $trays;
    }

    // ─── Ricoh Private MIB Counters ──────────────────────────────

    protected function pollRicohCounters(string $ip, string $community, int $version, int $port, Printer $printer): void
    {
        // ricohEngCounterTotal:   1.3.6.1.4.1.367.3.2.1.2.19.1.0
        // ricohEngCounterPrinter: 1.3.6.1.4.1.367.3.2.1.2.19.2.0
        // ricohEngCounterFax:     1.3.6.1.4.1.367.3.2.1.2.19.3.0
        // ricohEngCounterCopier:  1.3.6.1.4.1.367.3.2.1.2.19.4.0
        // Color pages:            1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.21
        // B/W pages:              1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.22
        // Scanner send:           1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.27
        // Fax transmission:       1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.28

        $total   = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.1.0', $version, $port);
        $print   = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.2.0', $version, $port);
        $fax     = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.3.0', $version, $port);
        $copy    = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.4.0', $version, $port);
        $color   = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.21', $version, $port);
        $mono    = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.22', $version, $port);
        $scan    = $this->snmpGetInt($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.27', $version, $port);

        if ($total !== null && $total > 0)  $printer->page_count_total = $total;
        if ($print !== null && $print >= 0) $printer->page_count_print = $print;
        if ($fax !== null && $fax >= 0)     $printer->page_count_fax   = $fax;
        if ($copy !== null && $copy >= 0)   $printer->page_count_copy  = $copy;
        if ($color !== null && $color >= 0) $printer->page_count_color = $color;
        if ($mono !== null && $mono >= 0)   $printer->page_count_mono  = $mono;
        if ($scan !== null && $scan >= 0)   $printer->page_count_scan  = $scan;
    }

    // ─── Threshold Alerting ──────────────────────────────────────

    protected function checkAlerts(Printer $printer): void
    {
        $toners = [
            'Black'   => $printer->toner_black,
            'Cyan'    => $printer->toner_cyan,
            'Magenta' => $printer->toner_magenta,
            'Yellow'  => $printer->toner_yellow,
        ];

        foreach ($toners as $color => $level) {
            if ($level === null || $level < 0) continue;

            $eventKey = "printer_{$printer->id}_toner_" . strtolower($color);

            if ($level <= $printer->toner_critical_threshold) {
                $this->raiseEvent($printer, "critical",
                    "{$printer->printer_name}: {$color} toner critically low ({$level}%)",
                    "Replace {$color} toner cartridge immediately. Level: {$level}%",
                    $eventKey
                );
            } elseif ($level <= $printer->toner_warning_threshold) {
                $this->raiseEvent($printer, "warning",
                    "{$printer->printer_name}: {$color} toner low ({$level}%)",
                    "Order replacement {$color} toner cartridge. Level: {$level}%",
                    $eventKey
                );
            }
        }

        // Paper tray alerts
        if ($printer->paper_trays) {
            $trays = is_array($printer->paper_trays) ? $printer->paper_trays : json_decode($printer->paper_trays, true);
            foreach ($trays ?? [] as $tray) {
                if (!isset($tray['max']) || $tray['max'] <= 0) continue;
                $pct = (int) round(($tray['current'] / $tray['max']) * 100);
                if ($pct <= $printer->paper_warning_threshold) {
                    $trayName = $tray['name'] ?? 'Unknown Tray';
                    $eventKey = "printer_{$printer->id}_paper_" . md5($trayName);
                    $this->raiseEvent($printer, $pct === 0 ? 'critical' : 'warning',
                        "{$printer->printer_name}: {$trayName} paper low ({$pct}%)",
                        "Refill {$trayName} on {$printer->printer_name}. Level: {$tray['current']}/{$tray['max']}",
                        $eventKey
                    );
                }
            }
        }

        // Error state alerts
        if ($printer->error_state && $printer->error_state !== 'normal') {
            $eventKey = "printer_{$printer->id}_error";
            $severity = in_array($printer->error_state, ['jammed', 'no_paper', 'no_toner', 'door_open', 'offline'])
                ? 'critical' : 'warning';
            $this->raiseEvent($printer, $severity,
                "{$printer->printer_name}: " . str_replace('_', ' ', ucfirst($printer->error_state)),
                "Printer {$printer->printer_name} ({$printer->ip_address}) reports error: {$printer->error_state}",
                $eventKey
            );
        }
    }

    protected function raiseEvent(Printer $printer, string $severity, string $title, string $message, string $eventKey): void
    {
        // De-duplicate: check for existing open event with same entity
        $existing = NocEvent::where('source_type', 'printer')
            ->where('source_id', $printer->id)
            ->where('entity_id', $eventKey)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        if ($existing) {
            $existing->update([
                'last_seen' => now(),
                'severity'  => $severity,
                'message'   => $message,
            ]);
            return;
        }

        NocEvent::create([
            'module'      => 'assets',
            'entity_type' => 'printer',
            'entity_id'   => $eventKey,
            'source_type' => 'printer',
            'source_id'   => $printer->id,
            'severity'    => $severity,
            'title'       => $title,
            'message'     => $message,
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
            'cooldown_minutes' => 30,
        ]);
    }

    // ─── SNMP Helpers ────────────────────────────────────────────

    protected function snmpGet(string $ip, string $community, string $oid, int $version, int $port): ?string
    {
        try {
            if (extension_loaded('snmp')) {
                $session = new \SNMP($version, "{$ip}:{$port}", $community, 1500000, 1);
                $session->valueretrieval = \SNMP_VALUE_LIBRARY;
                $result = @$session->get($oid);
                $session->close();
                return $result !== false ? $result : null;
            }
            // CLI fallback
            $v = $version === \SNMP::VERSION_1 ? '1' : '2c';
            $cmd = "snmpget -v {$v} -c " . escapeshellarg($community) . " -On {$ip}:{$port} " . escapeshellarg($oid) . " 2>/dev/null";
            $output = @shell_exec($cmd);
            if ($output && preg_match('/=\s*(.+)$/', trim($output), $m)) {
                return trim($m[1]);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function snmpGetInt(string $ip, string $community, string $oid, int $version, int $port): ?int
    {
        $val = $this->snmpGet($ip, $community, $oid, $version, $port);
        return $val !== null ? $this->parseIntValue($val) : null;
    }

    protected function snmpWalk(string $ip, string $community, string $oid, int $version, int $port): ?array
    {
        try {
            if (extension_loaded('snmp')) {
                $session = new \SNMP($version, "{$ip}:{$port}", $community, 1500000, 1);
                $session->valueretrieval = \SNMP_VALUE_LIBRARY;
                $result = @$session->walk($oid);
                $session->close();
                return is_array($result) && !empty($result) ? $result : null;
            }
            // CLI fallback
            $v = $version === \SNMP::VERSION_1 ? '1' : '2c';
            $cmd = "snmpwalk -v {$v} -c " . escapeshellarg($community) . " -On {$ip}:{$port} " . escapeshellarg($oid) . " 2>/dev/null";
            $output = @shell_exec($cmd);
            if (!$output) return null;
            $results = [];
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('/^([.\d]+)\s*=\s*(.+)$/', trim($line), $m)) {
                    $results[ltrim($m[1], '.')] = trim($m[2]);
                }
            }
            return !empty($results) ? $results : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function snmpVersion(?string $ver): int
    {
        return match ($ver) {
            'v1'    => \SNMP::VERSION_1,
            'v3'    => \SNMP::VERSION_3,
            default => \SNMP::VERSION_2c,
        };
    }

    protected function parseIntValue(?string $val): ?int
    {
        if ($val === null) return null;
        // Strip SNMP type prefix: "INTEGER: 42", "Gauge32: 100", "Counter32: 500"
        if (preg_match('/(?:INTEGER|Gauge32|Counter32|Counter64):\s*(-?\d+)/i', $val, $m)) {
            return (int) $m[1];
        }
        if (is_numeric(trim($val))) {
            return (int) trim($val);
        }
        return null;
    }

    protected function cleanValue(?string $val): ?string
    {
        if (!$val) return null;
        $val = preg_replace('/^[A-Z][a-zA-Z0-9-]+:\s*/', '', $val);
        return trim(trim($val, '"'));
    }

    protected function lastOidIndex(string $oid): string
    {
        $parts = explode('.', $oid);
        return end($parts);
    }

    protected function lastTwoOidIndexes(string $oid): string
    {
        $parts = explode('.', $oid);
        $count = count($parts);
        if ($count >= 2) {
            return $parts[$count - 2] . '.' . $parts[$count - 1];
        }
        return end($parts);
    }

    protected function findByIndex(?array $walkData, string $index): ?string
    {
        if (!$walkData) return null;
        foreach ($walkData as $oid => $val) {
            if (str_ends_with($oid, '.' . $index)) {
                return $val;
            }
        }
        return null;
    }

    protected function isRicoh(?string $sysDescr, string $manufacturer): bool
    {
        $check = strtolower(($sysDescr ?? '') . ' ' . $manufacturer);
        return str_contains($check, 'ricoh') || str_contains($check, 'nrg') || str_contains($check, 'lanier');
    }

    protected function descContainsToner(string $desc, string $color): bool
    {
        return (str_contains($desc, 'toner') || str_contains($desc, 'cartridge') || str_contains($desc, 'ink'))
            && str_contains($desc, $color);
    }

    // ─── Normalized Supplies Sync ────────────────────────────────

    protected function syncSupplies(Printer $printer, string $ip, string $community, int $version, int $port): void
    {
        $descrs    = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.6.1', $version, $port) ?? [];
        $types     = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.7.1', $version, $port) ?? [];
        $maxCaps   = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.8.1', $version, $port) ?? [];
        $curLevels = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.43.11.1.1.9.1', $version, $port) ?? [];

        if (empty($descrs) && empty($curLevels)) return;

        // For Ricoh: build a reliable color→percent map using name-based matching
        // (same logic as pollRicohToner) so index mismatches never cause N/A.
        $isRicoh      = $this->isRicohPrinter($printer);
        $ricohColorMap = []; // ['black' => 40, 'cyan' => 90, ...]
        if ($isRicoh) {
            $ricohNames  = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.24.1.1.3', $version, $port) ?? [];
            $ricohLevels = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5', $version, $port) ?? [];

            foreach ($ricohNames as $nameOid => $nameVal) {
                $nameClean  = strtolower((string) $this->cleanValue($nameVal));
                $ricohIndex = $this->lastOidIndex($nameOid);

                // Find the matching level by OID index
                $level = null;
                foreach ($ricohLevels as $levelOid => $levelVal) {
                    if ($this->lastOidIndex($levelOid) == $ricohIndex) {
                        $level = $this->parseIntValue($levelVal);
                        break;
                    }
                }
                if ($level === null) continue;

                // Normalise Ricoh special values (same logic as pollRicohToner):
                //   -2 = unknown          → skip (null); let standard MIB fallback apply
                //   -3 = some remaining   → approximate 10%
                //   -1 = no restriction   → 100%
                //   < -3 = almost empty   → 0%
                if ($level === -2) continue;          // unknown — skip this entry
                if ($level === -1) $level = 100;
                elseif ($level === -3) $level = 10;
                elseif ($level < -3)  $level = 0;
                if ($level > 100) $level = 100;

                if (str_contains($nameClean, 'black') || str_contains($nameClean, 'bk')) {
                    $ricohColorMap['black']   = $level;
                } elseif (str_contains($nameClean, 'cyan')) {
                    $ricohColorMap['cyan']    = $level;
                } elseif (str_contains($nameClean, 'magenta')) {
                    $ricohColorMap['magenta'] = $level;
                } elseif (str_contains($nameClean, 'yellow')) {
                    $ricohColorMap['yellow']  = $level;
                } elseif (str_contains($nameClean, 'waste')) {
                    $ricohColorMap['waste']   = $level;
                }
            }
        }

        $seenIndexes   = [];
        $handledColors = []; // tracks which colors were handled by standard MIB loop

        foreach ($descrs as $oid => $descr) {
            $index = (int) substr($oid, strrpos($oid, '.') + 1);
            $seenIndexes[] = $index;

            $descr = trim(strip_tags($descr));
            $descrLower = strtolower($descr);

            // Determine color
            $color = 'black';
            if (str_contains($descrLower, 'cyan') || str_contains($descrLower, 'blue')) $color = 'cyan';
            elseif (str_contains($descrLower, 'magenta') || str_contains($descrLower, 'red')) $color = 'magenta';
            elseif (str_contains($descrLower, 'yellow')) $color = 'yellow';
            elseif (str_contains($descrLower, 'waste') || str_contains($descrLower, 'collection')) $color = 'waste';

            // Determine type from prtMarkerSuppliesType integer
            $typeKey = str_replace('.6.1.', '.7.1.', $oid);
            $typeInt = isset($types[$typeKey]) ? $this->parseIntValue($types[$typeKey]) : 3;
            $supplyType = match($typeInt) {
                7  => 'drum',
                12 => 'fuser',
                15 => 'waste',
                default => str_contains($descrLower, 'drum') ? 'drum' :
                           (str_contains($descrLower, 'fuser') ? 'fuser' :
                           (str_contains($descrLower, 'waste') ? 'waste' : 'toner'))
            };

            // Get level OID
            $levelKey = str_replace('.6.1.', '.9.1.', $oid);
            $maxKey   = str_replace('.6.1.', '.8.1.', $oid);
            $rawLevel = isset($curLevels[$levelKey]) ? $this->parseIntValue($curLevels[$levelKey]) : null;
            $rawMax   = isset($maxCaps[$maxKey])     ? $this->parseIntValue($maxCaps[$maxKey])     : null;

            // Normalize to percent from standard MIB values
            $percent = null;
            if ($rawLevel !== null) {
                if ($rawLevel === -1) $percent = 100; // no restriction
                elseif ($rawLevel === -2) $percent = null; // unknown
                elseif ($rawLevel === -3) $percent = 10;  // some remaining
                elseif ($rawMax !== null && $rawMax > 0) {
                    $percent = (int) min(100, max(0, round(($rawLevel / $rawMax) * 100)));
                } elseif ($rawMax === -1) {
                    $percent = $rawLevel; // already a percentage
                }
            }

            // For Ricoh: override with the name-matched color map (reliable, no index ambiguity)
            if ($isRicoh && isset($ricohColorMap[$color])) {
                $percent = $ricohColorMap[$color];
            }

            $handledColors[] = $color; // mark this color as handled by standard MIB

            // Fetch previous record for consumption rate
            $existing = \App\Models\PrinterSupply::where('printer_id', $printer->id)
                            ->where('supply_index', $index)->first();

            $consumptionRate = $existing->consumption_rate ?? null;
            $estimatedDays = null;

            if ($existing && $existing->supply_percent !== null && $percent !== null
                && $existing->last_updated_at && $percent < $existing->supply_percent) {
                $hoursDiff = $existing->last_updated_at->diffInHours(now());
                if ($hoursDiff > 0) {
                    $daysDiff = $hoursDiff / 24;
                    $consumed = $existing->supply_percent - $percent;
                    $ratePerDay = $consumed / $daysDiff;
                    // Exponential moving average (alpha=0.3)
                    $consumptionRate = $consumptionRate !== null
                        ? 0.3 * $ratePerDay + 0.7 * $consumptionRate
                        : $ratePerDay;
                    if ($consumptionRate > 0) {
                        $estimatedDays = (int) min(9999, $percent / $consumptionRate);
                    }
                }
            } elseif ($existing && $existing->consumption_rate !== null && $percent !== null) {
                $consumptionRate = $existing->consumption_rate;
                if ($consumptionRate > 0) {
                    $estimatedDays = (int) min(9999, $percent / $consumptionRate);
                }
            }

            \App\Models\PrinterSupply::updateOrCreate(
                ['printer_id' => $printer->id, 'supply_index' => $index],
                [
                    'supply_oid'           => $levelKey,
                    'supply_capacity_oid'  => $maxKey,
                    'supply_type'          => $supplyType,
                    'supply_color'         => $color,
                    'supply_descr'         => $descr,
                    'supply_capacity'      => ($rawMax !== null && $rawMax > 0) ? $rawMax : null,
                    'supply_current'       => $rawLevel,
                    'supply_percent'       => $percent,
                    'warning_threshold'    => $existing->warning_threshold ?? 20,
                    'critical_threshold'   => $existing->critical_threshold ?? 5,
                    'consumption_rate'     => $consumptionRate,
                    'estimated_days_remaining' => $estimatedDays,
                    'last_updated_at'      => now(),
                ]
            );
        }

        // ── Ricoh fallback ────────────────────────────────────────────
        // Some Ricoh models (especially mono) don't respond to the standard
        // supply description MIB (.43.11.1.1.6.1), leaving $descrs empty and
        // the main loop with nothing to iterate.  If we have Ricoh private MIB
        // data in $ricohColorMap for colors not handled above, create/update
        // supply records directly from the Ricoh data.
        if ($isRicoh && !empty($ricohColorMap)) {
            $fallbackIndex = 900; // high enough to avoid collisions with real MIB indexes
            foreach ($ricohColorMap as $color => $percent) {
                if ($percent === null) continue;
                if (in_array($color, $handledColors)) continue; // already covered above

                $supplyType = $color === 'waste' ? 'waste' : 'toner';
                $descr = ucfirst($color) . ($color === 'waste' ? ' Toner Bottle' : ' Toner Cartridge');

                // Re-use existing record's index if one exists for this color
                $existing = \App\Models\PrinterSupply::where('printer_id', $printer->id)
                    ->where('supply_color', $color)->first();
                $index = $existing?->supply_index ?? $fallbackIndex++;

                $seenIndexes[] = $index;

                $consumptionRate = $existing?->consumption_rate;
                $estimatedDays   = null;
                if ($existing && $existing->supply_percent !== null && $percent !== null
                    && $existing->last_updated_at && $percent < $existing->supply_percent) {
                    $hoursDiff = $existing->last_updated_at->diffInHours(now());
                    if ($hoursDiff > 0) {
                        $daysDiff = $hoursDiff / 24;
                        $ratePerDay = ($existing->supply_percent - $percent) / $daysDiff;
                        $consumptionRate = $consumptionRate !== null
                            ? 0.3 * $ratePerDay + 0.7 * $consumptionRate
                            : $ratePerDay;
                    }
                }
                if ($consumptionRate > 0 && $percent !== null) {
                    $estimatedDays = (int) min(9999, $percent / $consumptionRate);
                }

                \App\Models\PrinterSupply::updateOrCreate(
                    ['printer_id' => $printer->id, 'supply_index' => $index],
                    [
                        'supply_oid'               => null,
                        'supply_type'              => $supplyType,
                        'supply_color'             => $color,
                        'supply_descr'             => $descr,
                        'supply_percent'           => $percent,
                        'warning_threshold'        => $existing?->warning_threshold  ?? 20,
                        'critical_threshold'       => $existing?->critical_threshold ?? 5,
                        'consumption_rate'         => $consumptionRate,
                        'estimated_days_remaining' => $estimatedDays,
                        'last_updated_at'          => now(),
                    ]
                );
            }
        }

        // Remove orphan supplies no longer reported by printer
        if (!empty($seenIndexes)) {
            \App\Models\PrinterSupply::where('printer_id', $printer->id)
                ->whereNotIn('supply_index', $seenIndexes)
                ->delete();
        }
    }

    protected function isRicohPrinter(Printer $printer): bool
    {
        $desc = strtolower($printer->snmp_sys_description ?? '');
        return str_contains($desc, 'ricoh') || str_contains($desc, 'nrg') || str_contains($desc, 'lanier');
    }

    protected function parseErrorState(?string $raw): string
    {
        if (!$raw) return 'normal';

        // Parse hex string like "00" or "00 00"
        $hexStr = preg_replace('/[^0-9a-fA-F]/', '', $raw);
        if (empty($hexStr) || $hexStr === '00' || $hexStr === '0000') {
            return 'normal';
        }

        $byte1 = hexdec(substr($hexStr, 0, 2));

        $states = [
            0x80 => 'low_paper',
            0x40 => 'no_paper',
            0x20 => 'low_toner',
            0x10 => 'no_toner',
            0x08 => 'door_open',
            0x04 => 'jammed',
            0x02 => 'offline',
            0x01 => 'service_needed',
        ];

        foreach ($states as $mask => $state) {
            if ($byte1 & $mask) {
                return $state;
            }
        }

        return 'normal';
    }
}
