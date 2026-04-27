<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Models\SensorMetric;
use App\Models\SnmpSensor;
use App\Models\NocEvent;
use App\Services\NotificationService;
use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CollectSnmpMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?MonitoredHost $singleHost;

    public function __construct(?MonitoredHost $host = null)
    {
        $this->singleHost = $host;
    }

    public function handle(): void
    {
        @set_time_limit(600); // 10 minutes max for all hosts
        @ini_set('memory_limit', '512M');

        if ($this->singleHost) {
            $hosts = collect([$this->singleHost->load(['snmpSensors', 'mib'])]);
        } else {
            $hosts = MonitoredHost::with(['snmpSensors', 'mib'])
                ->where('snmp_enabled', true)
                ->where('status', '!=', 'down')
                ->get();
        }

        if (!SnmpClient::isSnmpExtensionLoaded()) {
            Log::warning("CollectSnmpMetricsJob: PHP SNMP extension not loaded — will attempt CLI fallback.");
        }

        foreach ($hosts as $host) {
            // Give each host up to 3 minutes to finish.
            @set_time_limit(180); 
            $this->pollHost($host);
        }
    }

    protected function pollHost(MonitoredHost $host): void
    {
        if ($host->snmpSensors->isEmpty()) {
            return;
        }

        // Redis cache lock: skip if this host was recently polled
        try {
            if (Cache::store('redis')->has("snmp_lock_{$host->id}")) {
                Log::debug("CollectSnmpMetricsJob: Skipping host {$host->ip} (ID: {$host->id}) — cache lock active.");
                return;
            }
        } catch (\Throwable $e) {
            // Redis unavailable — continue polling regardless
            Log::debug("CollectSnmpMetricsJob: Redis cache check failed for host {$host->id}, continuing poll.", [
                'error' => $e->getMessage(),
            ]);
        }

        $client = null;
        try {
            $client = new SnmpClient($host);
            $client->connect();

            $snmpSuccess = false;
            $sensors = $host->snmpSensors;
            
            // Chunk sensors to avoid too many OIDs in a single SNMP packet
            foreach ($sensors->chunk(20) as $chunk) {
                $oids = $chunk->pluck('oid')->toArray();
                $results = $client->getMultiple($oids);

                foreach ($chunk as $sensor) {
                    try {
                        // try to find the result by numeric OID or original OID
                        $cleanOid = ltrim($sensor->oid, '.');
                        $rawResult = $results[$cleanOid] ?? $results[$sensor->oid] ?? false;

                        // Fallback to individual get if bulk failed for this sensor or returned nothing
                        if ($rawResult === false) {
                            $rawResult = $client->get($sensor->oid);
                        }

                        if ($rawResult === false) {
                            $this->recordSensorFailure($sensor, $host);
                            continue;
                        }

                        $snmpSuccess = true;
                        $parsedValue = $this->parseValue($rawResult);
                        $finalValue = $parsedValue;
                        $skipMetric = false;

                        // Normalize Status Values for Boolean Sensors
                        if ($sensor->data_type === 'boolean') {
                            if ($sensor->sensor_group === 'interface_status' || str_contains($sensor->oid, '1.3.6.1.2.1.2.2.1.8')) {
                                // Standard ifOperStatus: 1=up, everything else (2=down, 3=testing, etc.) = down
                                $finalValue = ($parsedValue == 1) ? 1.0 : 0.0;
                            } elseif ($sensor->sensor_group === 'VPN') {
                                // Sophos VPN status: 2=active, 1=connecting, 0=inactive
                                $finalValue = ($parsedValue >= 1) ? 1.0 : 0.0;
                            } else {
                                // Default boolean logic: 1 is up/true, everything else is down/false
                                $finalValue = ($parsedValue == 1) ? 1.0 : 0.0;
                            }
                        }

                        // Rate-based network/system counters (bandwidth, etc.)
                        if ($sensor->data_type === 'counter') {
                            $isFirstPoll = ($sensor->last_raw_counter === null);
                            $finalValue = $this->calculateCounterRate($sensor, $parsedValue);
                            if ($isFirstPoll) $skipMetric = true;
                        }

                        // Absolute page-counter — store raw cumulative total, not a rate
                        if ($sensor->data_type === 'absolute_counter') {
                            $sensor->update(['last_raw_counter' => $parsedValue]);
                            $finalValue = $parsedValue;
                        }

                        // Ricoh toner gauge — OID returns positive percentages (0-100) normally.
                        // Special codes: -1 = no restriction (full), -2 = unknown, -3 = some remaining.
                        // -100 (any < -3) = "Cartridge Almost Empty" alert → 0%.
                        // DO NOT use abs(): abs(-100)=100 inverts the reading entirely.
                        if ($sensor->data_type === 'toner_gauge') {
                            if ($finalValue == -1.0) {
                                $finalValue = 100.0;
                            } elseif ($finalValue == -2.0) {
                                $finalValue = 0.0;
                            } elseif ($finalValue == -3.0) {
                                $finalValue = 5.0;
                            } elseif ($finalValue < -3.0) {
                                $finalValue = 0.0;  // empty/alert — NOT abs()
                            }
                            $finalValue = (float) min(100, max(0, $finalValue));
                        }

                        if (!$skipMetric) {
                            SensorMetric::create([
                                'sensor_id' => $sensor->id,
                                'value' => $finalValue,
                                'recorded_at' => now(),
                            ]);
                            $this->checkThresholds($host, $sensor, $finalValue);
                            $this->checkDuplexAlert($host, $sensor, $finalValue);
                        }

                        $sensor->update(['status' => 'active', 'consecutive_failures' => 0]);

                    } catch (\Exception $e) {
                        Log::error("Error processing sensor {$sensor->name} on {$host->ip}: " . $e->getMessage());
                    }
                }
            }

            if ($snmpSuccess) {
                $host->last_snmp_at = now();
                if ($host->status === 'degraded') {
                    $host->status = 'up';
                }
                $host->save();

                // Set Redis cache lock after successful poll (240 second TTL)
                try {
                    Cache::store('redis')->put("snmp_lock_{$host->id}", true, 240);
                } catch (\Throwable $e) {
                    // Redis unavailable — continue without lock
                    Log::debug("CollectSnmpMetricsJob: Failed to set Redis cache lock for host {$host->id}.", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error("SNMP session failed", [
                'host' => $host->ip,
                'snmp_version' => $host->snmp_version,
                'port' => $host->snmp_port ?? 161,
                'community_set' => !empty($host->snmp_community),
                'error' => $e->getMessage(),
            ]);
            if ($host->status === 'up') {
                $host->update(['status' => 'degraded']);
            }
        } finally {
            $client?->close();
        }
    }

    protected function calculateCounterRate(SnmpSensor $sensor, float $currentValue): float
    {
        $lastRaw = $sensor->last_raw_counter;
        $lastTime = $sensor->last_recorded_at;

        // Persist current raw counter and timestamp to database
        $sensor->update([
            'last_raw_counter' => $currentValue,
            'last_recorded_at' => now(),
        ]);

        if ($lastRaw === null || $lastTime === null) {
            return 0;
        }

        $timeDiff = now()->timestamp - $lastTime->timestamp;
        if ($timeDiff <= 0) {
            return 0;
        }

        $diffValue = $currentValue - $lastRaw;

        // Handle counter wraparound
        if ($diffValue < 0) {
            // Try 32-bit wraparound first (most common for SNMP counters)
            $wrap32 = (pow(2, 32) - $lastRaw) + $currentValue;
            // Try 64-bit wraparound for HC counters
            $wrap64 = (pow(2, 64) - $lastRaw) + $currentValue;

            // Use the smaller wrap value — a 32-bit counter wrapping is far more likely
            // than a legitimate 64-bit value jump, unless the previous value was very large
            if ($lastRaw > pow(2, 32)) {
                $diffValue = $wrap64;
            } else {
                $diffValue = $wrap32;
            }
        }

        $rate = $diffValue / $timeDiff;

        // Clamp unreasonable spikes: cap at 10 Gbps equivalent for traffic counters
        // or 1 billion units/sec for generic counters
        $maxRate = 1250000000; // ~10 Gbps in bytes/sec
        $rate = min($rate, $maxRate);
        $rate = max($rate, 0);

        return $rate;
    }

    protected function recordSensorFailure(SnmpSensor $sensor, MonitoredHost $host): void
    {
        $failures = ($sensor->consecutive_failures ?? 0) + 1;
        $newStatus = $sensor->status ?? 'active';

        // Only mark unreachable after 10+ consecutive failures (not too aggressive)
        if ($failures >= 20) {
            $newStatus = 'error';
        } elseif ($failures >= 10) {
            if ($newStatus === 'active') {
                $newStatus = 'unreachable';
                Log::warning("Sensor marked unreachable after {$failures} consecutive failures", [
                    'sensor' => $sensor->name,
                    'oid' => $sensor->oid,
                    'host' => $host->ip,
                ]);
            }
        }

        $sensor->update([
            'consecutive_failures' => $failures,
            'status' => $newStatus,
        ]);
    }

    protected function parseValue(string $value): float
    {
        // Handle numeric values
        if (preg_match('/(?:INTEGER|Gauge32|Counter32|Counter64|Unsigned32|TimeTicks):\s*(-?\d+)/i', $value, $matches)) {
            return (float) $matches[1];
        }

        // Counter64, Counter32, Gauge32, INTEGER, etc.
        // We look for a colon or space followed by digits to skip "64" in "Counter64: 123"
        if (preg_match('/(?:[:\s]|^)(-?\d+(?:\.\d+)?)\s*$/', $value, $matches)) {
            return (float) $matches[1];
        }

        // STRING-like status conversions
        $lower = strtolower($value);
        $downStates = ['unreachable', 'unavailable', 'down', 'inactive', 'notconnect', 'unregistered'];
        $upStates = ['reachable', 'up', 'running', 'active', 'idle', 'registered', 'ringing', 'inuse'];

        foreach ($downStates as $state) {
            if (str_contains($lower, $state)) return 0;
        }
        foreach ($upStates as $state) {
            if (str_contains($lower, $state)) return 1;
        }

        Log::debug("parseValue: Could not parse value, defaulting to 0", ['raw' => $value]);
        return 0;
    }

    protected function checkDuplexAlert(MonitoredHost $host, SnmpSensor $sensor, float $value): void
    {
        if ($sensor->sensor_group !== 'interface_duplex') {
            return;
        }

        $sensorName = $sensor->name ?: $sensor->description ?: $sensor->oid;
        $eventTitle = "Half-Duplex Detected: {$host->name} - {$sensorName}";

        if ((int) $value === 2) {
            // Value 2 = half-duplex — possible duplex mismatch
            $existingEvent = NocEvent::where('source_id', $host->id)
                ->where('source_type', 'snmp_threshold')
                ->where('status', 'open')
                ->where('title', $eventTitle)
                ->first();

            if (!$existingEvent) {
                NocEvent::create([
                    'module'      => 'snmp',
                    'source_type' => 'snmp_threshold',
                    'source_id'   => $host->id,
                    'title'       => $eventTitle,
                    'message'     => "Interface {$sensorName} is operating in half-duplex mode — possible duplex mismatch",
                    'severity'    => 'warning',
                    'status'      => 'open',
                    'first_seen'  => now(),
                    'last_seen'   => now(),
                ]);
            }
        } else {
            // Value is no longer 2 (full-duplex or unknown) — auto-resolve
            NocEvent::where('source_id', $host->id)
                ->where('source_type', 'snmp_threshold')
                ->where('status', 'open')
                ->where('title', $eventTitle)
                ->update([
                    'status'      => 'resolved',
                    'resolved_at' => now(),
                ]);
        }
    }

    protected function checkThresholds(MonitoredHost $host, SnmpSensor $sensor, float $value): void
    {
        // Toner / consumable sensors alert on LOW values (≤ threshold).
        // All other sensors (CPU, traffic, etc.) alert on HIGH values (≥ threshold).
        $isLowAlert = in_array($sensor->data_type, ['toner_gauge'])
            || in_array(strtolower($sensor->sensor_group ?? ''), ['toner', 'consumables', 'paper']);

        $severity = null;
        if ($isLowAlert) {
            if ($sensor->critical_threshold !== null && $value <= $sensor->critical_threshold) {
                $severity = 'critical';
            } elseif ($sensor->warning_threshold !== null && $value <= $sensor->warning_threshold) {
                $severity = 'warning';
            }
        } else {
            if ($sensor->critical_threshold !== null && $value >= $sensor->critical_threshold) {
                $severity = 'critical';
            } elseif ($sensor->warning_threshold !== null && $value >= $sensor->warning_threshold) {
                $severity = 'warning';
            }
        }

        $sensorName = $sensor->name ?: $sensor->description ?: $sensor->oid;
        $eventTitle = $isLowAlert
            ? "Low Supply Alert: {$host->name} — {$sensorName}"
            : "SNMP Threshold Exceeded: {$host->name} — {$sensorName}";

        if ($severity) {
            $valDisplay = $sensor->unit ? "{$value} {$sensor->unit}" : $value;
            $message = $isLowAlert
                ? "{$sensorName} is low ({$valDisplay}). Please replace or refill."
                : "Sensor value {$valDisplay} exceeded {$severity} threshold.";

            $existingEvent = NocEvent::where('source_id', $host->id)
                ->where('source_type', 'snmp_threshold')
                ->where('status', 'open')
                ->where('title', $eventTitle)
                ->first();

            if (!$existingEvent) {
                NocEvent::create([
                    'module'      => 'snmp',
                    'source_type' => 'snmp_threshold',
                    'source_id'   => $host->id,
                    'title'       => $eventTitle,
                    'message'     => $message,
                    'severity'    => $severity,
                    'status'      => 'open',
                    'first_seen'  => now(),
                    'last_seen'   => now(),
                ]);

                // Send in-app notification + email to all admins for toner/supply alerts
                if ($isLowAlert) {
                    try {
                        app(NotificationService::class)->notifyAdmins(
                            type: 'supply_alert',
                            title: $eventTitle,
                            message: $message,
                            link: null,
                            severity: $severity
                        );
                    } catch (\Throwable) {
                        // Don't fail the job if notification dispatch fails
                    }
                }
            }
        } else {
            // Auto-resolve open threshold events when value drops below thresholds
            NocEvent::where('source_id', $host->id)
                ->where('source_type', 'snmp_threshold')
                ->where('status', 'open')
                ->where('title', $eventTitle)
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ]);
        }
    }
}
