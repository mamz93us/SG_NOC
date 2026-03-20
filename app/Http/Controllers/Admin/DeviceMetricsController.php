<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitoredHost;
use App\Models\SensorMetric;
use App\Models\SnmpSensor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceMetricsController extends Controller
{
    // ── Range helper ──────────────────────────────────────────────────────

    private function sinceFromRange(string $range): \Carbon\Carbon
    {
        return match ($range) {
            '1h'  => now()->subHour(),
            '6h'  => now()->subHours(6),
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),   // 24h
        };
    }

    /**
     * Bucket size in seconds depending on range (keeps series ≤ ~300 points).
     */
    private function bucketSeconds(string $range): int
    {
        return match ($range) {
            '1h'  => 60,      // 1-minute buckets → 60 points
            '6h'  => 300,     // 5-minute buckets → 72 points
            '24h' => 900,     // 15-minute buckets → 96 points
            '7d'  => 3600,    // 1-hour buckets → 168 points
            '30d' => 21600,   // 6-hour buckets → 120 points
            default => 900,
        };
    }

    /**
     * Build a bucketed time series for a set of sensor IDs.
     * Returns [[timestamp_ms, avg_value], ...]
     */
    private function bucketedSeries(array $sensorIds, \Carbon\Carbon $since, int $bucketSec): array
    {
        if (empty($sensorIds)) {
            return [];
        }

        $rows = DB::table('sensor_metrics')
            ->selectRaw("
                FLOOR(UNIX_TIMESTAMP(recorded_at) / {$bucketSec}) * {$bucketSec} AS bucket,
                AVG(value) AS avg_value
            ")
            ->whereIn('sensor_id', $sensorIds)
            ->where('recorded_at', '>=', $since)
            ->groupByRaw("FLOOR(UNIX_TIMESTAMP(recorded_at) / {$bucketSec}) * {$bucketSec}")
            ->orderBy('bucket')
            ->get();

        return $rows->map(fn($r) => [(int) $r->bucket * 1000, round((float) $r->avg_value, 4)])->all();
    }

    /**
     * Scale bytes/sec to the most appropriate unit.
     * Returns ['divisor' => float, 'unit' => string]
     */
    private function autoScaleUnit(array $series): array
    {
        $maxVal = 0;
        foreach ($series as $point) {
            $maxVal = max($maxVal, abs($point[1] ?? 0));
        }

        if ($maxVal >= 1_000_000_000) {
            return ['divisor' => 1_000_000_000, 'unit' => 'Gbps'];
        }
        if ($maxVal >= 1_000_000) {
            return ['divisor' => 1_000_000, 'unit' => 'Mbps'];
        }
        if ($maxVal >= 1_000) {
            return ['divisor' => 1_000, 'unit' => 'Kbps'];
        }
        return ['divisor' => 1, 'unit' => 'bps'];
    }

    // ── Endpoints ─────────────────────────────────────────────────────────

    /**
     * GET /network/monitoring/hosts/{host}/metrics/traffic?range=24h
     * Returns aggregate traffic (all interfaces summed) for Inbound + Outbound.
     */
    public function getTrafficData(Request $request, MonitoredHost $host): JsonResponse
    {
        $range     = $request->input('range', '24h');
        $since     = $this->sinceFromRange($range);
        $bucket    = $this->bucketSeconds($range);

        // Aggregate ALL interface traffic sensors for this host
        $inSensorIds = SnmpSensor::where('host_id', $host->id)
            ->where('sensor_group', 'interface_traffic')
            ->where('name', 'like', '% - Traffic In')
            ->pluck('id')
            ->all();

        $outSensorIds = SnmpSensor::where('host_id', $host->id)
            ->where('sensor_group', 'interface_traffic')
            ->where('name', 'like', '% - Traffic Out')
            ->pluck('id')
            ->all();

        $inData  = $this->bucketedSeries($inSensorIds,  $since, $bucket);
        $outData = $this->bucketedSeries($outSensorIds, $since, $bucket);

        // Auto-scale to best unit
        $allPoints = array_merge($inData, $outData);
        ['divisor' => $divisor, 'unit' => $unit] = $this->autoScaleUnit($allPoints);

        // Scale values and negate outbound for duplex mirror display
        $scale = fn($points, bool $negate = false) => array_map(function ($p) use ($divisor, $negate) {
            $val = round($p[1] / $divisor, 3);
            return [$p[0], $negate ? -$val : $val];
        }, $points);

        return response()->json([
            'series' => [
                ['name' => 'Inbound',  'data' => $scale($inData,  false)],
                ['name' => 'Outbound', 'data' => $scale($outData, true)],
            ],
            'unit'    => $unit,
            'divisor' => $divisor,
        ]);
    }

    /**
     * GET /network/monitoring/hosts/{host}/metrics/cpu?range=24h
     * Returns CPU usage time series (percentage).
     */
    public function getCpuData(Request $request, MonitoredHost $host): JsonResponse
    {
        $range  = $request->input('range', '24h');
        $since  = $this->sinceFromRange($range);
        $bucket = $this->bucketSeconds($range);

        // CPU sensors: sensor_group='system', name contains 'CPU'
        // Also catches names like 'CPU Usage (5m)', 'CPU Idle' (which we invert below)
        $cpuSensors = SnmpSensor::where('host_id', $host->id)
            ->where(fn($q) => $q->where('sensor_group', 'system')
                                 ->orWhere('sensor_group', 'cpu'))
            ->where(fn($q) => $q->where('name', 'like', '%CPU%')
                                 ->orWhere('name', 'like', '%cpu%'))
            ->get();

        // Prefer "CPU Usage" sensor, avoid "CPU Idle" if usage exists
        $usageSensor = $cpuSensors->first(fn($s) => stripos($s->name, 'idle') === false && stripos($s->name, 'usage') !== false)
                    ?? $cpuSensors->first(fn($s) => stripos($s->name, 'usage') !== false)
                    ?? $cpuSensors->first();

        if (! $usageSensor) {
            return response()->json(['series' => [['name' => 'CPU Usage', 'data' => []]], 'unit' => '%']);
        }

        $isIdle = stripos($usageSensor->name, 'idle') !== false;
        $data   = $this->bucketedSeries([$usageSensor->id], $since, $bucket);

        // If sensor is "CPU Idle", invert to usage %
        if ($isIdle) {
            $data = array_map(fn($p) => [$p[0], round(100 - $p[1], 2)], $data);
        }

        return response()->json([
            'series' => [['name' => 'CPU Usage', 'data' => $data]],
            'unit'   => '%',
        ]);
    }

    /**
     * GET /network/monitoring/hosts/{host}/metrics/memory
     * Returns latest memory stats in bytes.
     */
    public function getMemoryData(Request $request, MonitoredHost $host): JsonResponse
    {
        $memory = $host->load('snmpSensors.latestMetric')->latestMemory();

        if (! $memory) {
            return response()->json(['available' => false]);
        }

        return response()->json(array_merge(['available' => true], $memory));
    }

    /**
     * GET /network/monitoring/hosts/{host}/metrics/interfaces
     * Returns interface list with latest status and speed.
     */
    public function getInterfaceData(MonitoredHost $host): JsonResponse
    {
        $interfaces = SnmpSensor::where('host_id', $host->id)
            ->where('sensor_group', 'interface_status')
            ->with('latestMetric')
            ->get()
            ->map(fn($sensor) => [
                'name'         => str_replace(' - Status', '', $sensor->name),
                'index'        => $sensor->interface_index,
                'status'       => ($sensor->latestMetric?->value ?? 0) == 1 ? 'up' : 'down',
                'description'  => $sensor->description,
            ]);

        return response()->json($interfaces);
    }
}
