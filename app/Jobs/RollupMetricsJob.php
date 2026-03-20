<?php

namespace App\Jobs;

use App\Models\SensorMetric;
use App\Models\SensorMetricHourly;
use App\Models\SensorMetricDaily;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RollupMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(): void
    {
        $this->rollupHourly();
        $this->rollupDaily();
        $this->pruneRawMetrics();
        $this->pruneHourlyMetrics();
    }

    protected function rollupHourly(): void
    {
        // Only roll up completed hours (not the current hour)
        $cutoffHour = now()->startOfHour();

        // Aggregate all raw data before the cutoff, grouped by sensor + hour
        $pendingHours = DB::table('sensor_metrics')
            ->select(
                'sensor_id',
                DB::raw('DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00") as hour'),
                DB::raw('AVG(value) as value_avg'),
                DB::raw('MIN(value) as value_min'),
                DB::raw('MAX(value) as value_max'),
                DB::raw('COUNT(*) as sample_count')
            )
            ->where('recorded_at', '<', $cutoffHour)
            ->groupBy('sensor_id', DB::raw('DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00")'))
            ->get();

        $count = 0;
        foreach ($pendingHours as $row) {
            // Idempotent: skip if hourly rollup already exists for this sensor+hour
            $exists = SensorMetricHourly::where('sensor_id', $row->sensor_id)
                ->where('hour', $row->hour)
                ->exists();

            if (!$exists) {
                SensorMetricHourly::create([
                    'sensor_id'    => $row->sensor_id,
                    'hour'         => $row->hour,
                    'value_avg'    => $row->value_avg,
                    'value_min'    => $row->value_min,
                    'value_max'    => $row->value_max,
                    'sample_count' => $row->sample_count,
                ]);
                $count++;
            }
        }

        Log::info("[RollupMetricsJob] Created {$count} hourly rollup records");
    }

    protected function rollupDaily(): void
    {
        // Only roll up completed days (not today)
        $cutoffDate = now()->startOfDay()->toDateString();

        $pendingDays = DB::table('sensor_metrics_hourly')
            ->select(
                'sensor_id',
                DB::raw('DATE(hour) as date'),
                DB::raw('AVG(value_avg) as value_avg'),
                DB::raw('MIN(value_min) as value_min'),
                DB::raw('MAX(value_max) as value_max'),
                DB::raw('SUM(sample_count) as sample_count')
            )
            ->where('hour', '<', $cutoffDate)
            ->groupBy('sensor_id', DB::raw('DATE(hour)'))
            ->get();

        $count = 0;
        foreach ($pendingDays as $row) {
            // Idempotent: updateOrCreate so re-running does not produce duplicates
            SensorMetricDaily::updateOrCreate(
                ['sensor_id' => $row->sensor_id, 'date' => $row->date],
                [
                    'value_avg'    => $row->value_avg,
                    'value_min'    => $row->value_min,
                    'value_max'    => $row->value_max,
                    'sample_count' => $row->sample_count,
                ]
            );
            $count++;
        }

        Log::info("[RollupMetricsJob] Upserted {$count} daily rollup records");
    }

    protected function pruneRawMetrics(): void
    {
        $cutoff = now()->subDays(7);
        $deleted = 0;

        do {
            $count = SensorMetric::where('recorded_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $count;
        } while ($count > 0);

        Log::info("[RollupMetricsJob] Pruned {$deleted} raw metric records older than 7 days");
    }

    protected function pruneHourlyMetrics(): void
    {
        $cutoff = now()->subDays(90);
        $deleted = 0;

        do {
            $count = SensorMetricHourly::where('hour', '<', $cutoff)->limit(1000)->delete();
            $deleted += $count;
        } while ($count > 0);

        Log::info("[RollupMetricsJob] Pruned {$deleted} hourly metric records older than 90 days");
    }
}
