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

            foreach ($host->snmpSensors as $sensor) {
                try {
                    $rawResult = $client->get($sensor->oid);

                    if ($rawResult === false) {
                        $this->recordSensorFailure($sensor, $host);
                        continue;
                    }

                    Log::info("Polled {$sensor->oid} ({$sensor->name}) on {$host->ip}", [
                        'raw' => $rawResult,
                        'host' => $host->ip,
                        'snmp_version' => $host->snmp_version,
                        'port' => $host->snmp_port ?? 161,
                    ]);

                    $snmpSuccess = true;
                    $parsedValue = $this->parseValue($rawResult);
                    $finalValue = $parsedValue;
                    $skipMetric = false;

                    // Calculate rate for counters using database-persisted last_raw_counter
                    if ($sensor->data_type === 'counter') {
                        $isFirstPoll = ($sensor->last_raw_counter === null);
                        $finalValue = $this->calculateCounterRate($sensor, $parsedValue);
                        // Skip storing metric on first poll — we have no previous value to compute rate
                        if ($isFirstPoll) {
                            $skipMetric = true;
                        }
                    }

                    if (!$skipMetric) {
                        SensorMetric::create([
                            'sensor_id' => $sensor->id,
                            'value' => $finalValue,
                            'recorded_at' => now(),
                        ]);

                        $this->checkThresholds($host, $sensor, $finalValue);
                    }

                    // Always reset sensor to active on successful poll
                    $sensor->update([
                        'status' => 'active',
                        'consecutive_failures' => 0,
                    ]);

                } catch (\Exception $e) {
                    Log::error("Failed to poll sensor on {$host->ip}", [
                        'oid' => $sensor->oid,
                        'name' => $sensor->name,
                        'snmp_version' => $host->snmp_version,
                        'port' => $host->snmp_port ?? 161,
                        'error' => $e->getMessage(),
                    ]);
                    $this->recordSensorFailure($sensor, $host);
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

    protected function parseValue($value): float
    {
        if (!is_string($value)) {
            return 0;
        }

        // Timeticks: (12345) 1:23:45.67 → extract centisecond value
        if (preg_match('/Timeticks:\s*\((\d+)\)/', $value, $matches)) {
            return (float) $matches[1];
        }

        // Counter64, Counter32, Gauge32, INTEGER, etc.
        if (preg_match('/(-?\d+(\.\d+)?)/', $value, $matches)) {
            return (float) $matches[1];
        }

        // STRING-like status conversions
        $lower = strtolower($value);
        if (str_contains($lower, 'up') || str_contains($lower, 'running') || str_contains($lower, 'active')) {
            Log::debug("parseValue: Converted string to 1 (up)", ['raw' => $value]);
            return 1;
        }
        if (str_contains($lower, 'down') || str_contains($lower, 'inactive') || str_contains($lower, 'notconnect')) {
            Log::debug("parseValue: Converted string to 0 (down)", ['raw' => $value]);
            return 0;
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
