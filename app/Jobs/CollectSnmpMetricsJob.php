<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Models\SensorMetric;
use App\Models\SnmpSensor;
use App\Models\NocEvent;
use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

                        if ($sensor->data_type === 'counter') {
                            $isFirstPoll = ($sensor->last_raw_counter === null);
                            $finalValue = $this->calculateCounterRate($sensor, $parsedValue);
                            if ($isFirstPoll) $skipMetric = true;
                        }

                        if (!$skipMetric) {
                            SensorMetric::create([
                                'sensor_id' => $sensor->id,
                                'value' => $finalValue,
                                'recorded_at' => now(),
                            ]);
                            $this->checkThresholds($host, $sensor, $finalValue);
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
        // Handle standard SNMP Timeticks
        if (preg_match('/Timeticks:\s*\((\d+)\)/', $value, $matches)) {
            return (float) $matches[1];
        }

        // Handle numeric statuses carefully BEFORE general numeric extraction
        // Especially for Sophos VPN (2 = Active, 1 = Connecting, 0 = Inactive)
        if (preg_match('/^INTEGER:\s*(\d+)$/', trim($value), $m)) {
            $val = (int)$m[1];
            // 2 is Active, 1 is Connecting. We'll count both as "Up" (1.0) for status sensors.
            return ($val >= 1) ? 1.0 : 0.0;
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

    protected function checkThresholds(MonitoredHost $host, SnmpSensor $sensor, float $value): void
    {
        $severity = null;
        if ($sensor->critical_threshold !== null && $value >= $sensor->critical_threshold) {
            $severity = 'critical';
        } elseif ($sensor->warning_threshold !== null && $value >= $sensor->warning_threshold) {
            $severity = 'warning';
        }

        $sensorName = $sensor->name ?: $sensor->description ?: $sensor->oid;
        $eventTitle = "SNMP Threshold Exceeded: {$host->name} - {$sensorName}";

        if ($severity) {
            $message = "Sensor value {$value} {$sensor->unit} exceeded {$severity} threshold limit.";

            $existingEvent = NocEvent::where('source_id', $host->id)
                ->where('event_type', 'snmp_threshold')
                ->where('status', 'active')
                ->where('title', $eventTitle)
                ->first();

            if (!$existingEvent) {
                NocEvent::create([
                    'event_type' => 'snmp_threshold',
                    'source_id' => $host->id,
                    'title' => $eventTitle,
                    'description' => $message,
                    'severity' => $severity,
                    'status' => 'active',
                    'detected_at' => now(),
                ]);
            }
        } else {
            // Auto-resolve active threshold events when value drops below thresholds
            NocEvent::where('source_id', $host->id)
                ->where('event_type', 'snmp_threshold')
                ->where('status', 'active')
                ->where('title', $eventTitle)
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ]);
        }
    }
}
