<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a composite covering index on sensor_metrics(sensor_id, recorded_at DESC).
 *
 * This is the key index for latestOfMany('recorded_at') used by SnmpSensor::latestMetric().
 * Without it, every latestMetric lookup does a full table scan per sensor.
 * With it, MySQL can find the latest row per sensor in O(log n) time.
 *
 * Also adds an index on sensor_metrics_hourly and sensor_metrics_daily for rollup queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the default single-column index if it already exists, then add the composite one.
        Schema::table('sensor_metrics', function (Blueprint $table) {
            // The composite index replaces individual sensor_id index for latestOfMany queries.
            // Laravel's latestOfMany generates: ORDER BY recorded_at DESC LIMIT 1 WHERE sensor_id = ?
            $table->index(['sensor_id', 'recorded_at'], 'sensor_metrics_sensor_recorded_idx');
        });

        if (Schema::hasTable('sensor_metrics_hourly')) {
            Schema::table('sensor_metrics_hourly', function (Blueprint $table) {
                $table->index(['sensor_id', 'hour'], 'sensor_metrics_hourly_sensor_hour_idx');
            });
        }

        if (Schema::hasTable('sensor_metrics_daily')) {
            Schema::table('sensor_metrics_daily', function (Blueprint $table) {
                $table->index(['sensor_id', 'date'], 'sensor_metrics_daily_sensor_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sensor_metrics', function (Blueprint $table) {
            $table->dropIndex('sensor_metrics_sensor_recorded_idx');
        });

        if (Schema::hasTable('sensor_metrics_hourly')) {
            Schema::table('sensor_metrics_hourly', function (Blueprint $table) {
                $table->dropIndex('sensor_metrics_hourly_sensor_hour_idx');
            });
        }

        if (Schema::hasTable('sensor_metrics_daily')) {
            Schema::table('sensor_metrics_daily', function (Blueprint $table) {
                $table->dropIndex('sensor_metrics_daily_sensor_date_idx');
            });
        }
    }
};
