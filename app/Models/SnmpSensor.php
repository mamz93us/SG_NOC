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
        'monitor_enabled',
        'retired_at',
        'last_raw_counter',
        'last_recorded_at',
        'status',
        'sensor_group',
        'interface_index',
        'consecutive_failures',
    ];

    protected $casts = [
        'graph_enabled' => 'boolean',
        'monitor_enabled' => 'boolean',
        'retired_at' => 'datetime',
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

    // ─────────────────────────────────────────────────────────────
    // Monitoring state
    // ─────────────────────────────────────────────────────────────

    /** Sensors discovery still sees on the device. */
    public function scopeLive($query)
    {
        return $query->whereNull('retired_at');
    }

    /**
     * Sensors discovery no longer finds.
     *
     * Retired rather than deleted: sensor_metrics and metric_rollups both
     * cascade, so deleting on a walk that came back short would silently
     * destroy history that cannot be rebuilt.
     */
    public function scopeRetired($query)
    {
        return $query->whereNotNull('retired_at');
    }

    /** Live AND not muted by an operator — what should actually be judged. */
    public function scopeMonitored($query)
    {
        return $query->whereNull('retired_at')->where('monitor_enabled', true);
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    // ─────────────────────────────────────────────────────────────
    // Sophos VPN helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Sophos publishes two sensors per IPsec tunnel:
     *   "VPN: {name} - Active"      2 = enabled, 0 = administratively disabled
     *   "VPN: {name} - Connection"  1 = connected, 0 = disconnected
     *
     * The tunnel name is the part between them, and is how the two halves are
     * paired back together.
     */
    public function vpnTunnelName(): ?string
    {
        if ($this->sensor_group !== 'VPN') {
            return null;
        }

        $name = preg_replace('/^VPN:\s*/', '', (string) $this->name);
        $name = preg_replace('/\s*-\s*(Active|Connection)$/i', '', $name);

        return trim($name) ?: null;
    }

    public function isVpnConnectionSensor(): bool
    {
        return $this->sensor_group === 'VPN'
            && (bool) preg_match('/-\s*Connection$/i', (string) $this->name);
    }

    public function isVpnActiveSensor(): bool
    {
        return $this->sensor_group === 'VPN'
            && (bool) preg_match('/-\s*Active$/i', (string) $this->name);
    }

    public function sensorMetrics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SensorMetric::class, 'sensor_id');
    }

    /**
     * Latest single metric — uses latestOfMany() which generates ONE subquery
     * for ALL sensors together, replacing 15-40 N+1 queries per page load.
     * Eager load with: with('snmpSensors.latestMetric')
     */
    public function latestMetric(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SensorMetric::class, 'sensor_id')->latestOfMany('recorded_at');
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
