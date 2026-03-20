<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnmpSensor extends Model
{
    protected $fillable = [
        'host_id',
        'name',
        'oid',
        'description',
        'data_type',
        'unit',
        'poll_interval',
        'warning_threshold',
        'critical_threshold',
        'graph_enabled',
        'last_raw_counter',
        'last_recorded_at',
        'status',
        'sensor_group',
        'interface_index',
        'consecutive_failures',
    ];

    protected $casts = [
        'graph_enabled' => 'boolean',
        'warning_threshold' => 'float',
        'critical_threshold' => 'float',
        'last_raw_counter' => 'float',
        'last_recorded_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'interface_index' => 'integer',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'host_id');
    }

    public function sensorMetrics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SensorMetric::class, 'sensor_id');
    }

    public function hourlyMetrics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\SensorMetricHourly::class, 'sensor_id');
    }

    public function dailyMetrics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\SensorMetricDaily::class, 'sensor_id');
    }

    /**
     * Get the best available data points for graphing.
     * Returns hourly data for the last 7 days, daily data beyond that.
     */
    public function getGraphData(int $days = 30): array
    {
        if ($days <= 7) {
            return $this->hourlyMetrics()
                ->where('hour', '>=', now()->subDays($days))
                ->orderBy('hour')
                ->get(['hour as ts', 'value_avg as value', 'value_min', 'value_max'])
                ->toArray();
        }

        return $this->dailyMetrics()
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get(['date as ts', 'value_avg as value', 'value_min', 'value_max'])
            ->toArray();
    }
}
