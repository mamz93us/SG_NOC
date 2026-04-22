<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoredHost extends Model
{
    protected $fillable = [
        'device_id',
        'branch_id',
        'vpn_id',
        'name',
        'ip',
        'type',
        'discovered_type',
        'ping_enabled',
        'ping_interval_seconds',
        'ping_packet_count',
        'alert_email',
        'snmp_enabled',
        'snmp_version',
        'snmp_community',
        'snmp_port',
        'snmp_auth_user',
        'snmp_auth_password',
        'snmp_auth_protocol',
        'snmp_priv_password',
        'snmp_priv_protocol',
        'snmp_security_level',
        'snmp_context_name',
        'mib_id',
        'alert_enabled',
        'status',
        'last_ping_at',
        'last_snmp_at',
        'last_checked_at',
    ];

    public function mib(): BelongsTo
    {
        return $this->belongsTo(Mib::class);
    }

    protected $hidden = [
        'snmp_community',
        'snmp_auth_password',
        'snmp_priv_password',
    ];

    protected $casts = [
        'ping_enabled' => 'boolean',
        'snmp_enabled' => 'boolean',
        'alert_enabled' => 'boolean',
        // 'snmp_community' removed from casts to handle manually with error catching
        'last_ping_at' => 'datetime',
        'last_snmp_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function vpnTunnel(): BelongsTo
    {
        return $this->belongsTo(VpnTunnel::class, 'vpn_id');
    }

    public function getSnmpCommunityAttribute($value)
    {
        if (empty($value)) return 'public';
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::warning("Decryption failed for host {$this->id} ({$this->name}) community string. MAC probably invalid. Defaulting to public.");
            return 'public';
        }
    }

    public function setSnmpCommunityAttribute($value)
    {
        $this->attributes['snmp_community'] = encrypt($value);
    }

    public function getSnmpAuthPasswordAttribute($value)
    {
        if (empty($value)) return null;
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::warning("Decryption failed for host {$this->id} ({$this->name}) snmp_auth_password. Returning null.");
            return null;
        }
    }

    public function setSnmpAuthPasswordAttribute($value)
    {
        $this->attributes['snmp_auth_password'] = !empty($value) ? encrypt($value) : null;
    }

    public function getSnmpPrivPasswordAttribute($value)
    {
        if (empty($value)) return null;
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::warning("Decryption failed for host {$this->id} ({$this->name}) snmp_priv_password. Returning null.");
            return null;
        }
    }

    public function setSnmpPrivPasswordAttribute($value)
    {
        $this->attributes['snmp_priv_password'] = !empty($value) ? encrypt($value) : null;
    }

    public function hostChecks(): HasMany
    {
        return $this->hasMany(HostCheck::class, 'host_id');
    }

    public function snmpSensors(): HasMany
    {
        return $this->hasMany(SnmpSensor::class, 'host_id');
    }

    // ─── Dashboard / Chart helpers ────────────────────────────────────────

    /**
     * Get the latest metric value for a given sensor_group.
     * e.g. $host->latestMetric('system') → 34.5
     * Searches sensor name for keywords when no exact group match found.
     */
    public function latestMetric(string $sensorGroup): ?float
    {
        // Try exact sensor_group first
        $sensor = $this->snmpSensors()
            ->where('sensor_group', $sensorGroup)
            ->with('latestMetric')
            ->first();

        if ($sensor?->latestMetric) {
            return (float) $sensor->latestMetric->value;
        }

        // Fallback: search by name keyword
        $keyword = match ($sensorGroup) {
            'cpu_pct', 'cpu'   => 'cpu',
            'memory_pct'       => 'memory',
            'storage_pct'      => 'storage',
            default            => $sensorGroup,
        };

        $sensor = $this->snmpSensors()
            ->where(fn($q) => $q->where('sensor_group', 'like', "%{$keyword}%")
                                ->orWhere('name', 'like', "%{$keyword}%"))
            ->with('latestMetric')
            ->first();

        return $sensor?->latestMetric ? (float) $sensor->latestMetric->value : null;
    }

    /**
     * Get latest memory stats from sensors.
     * Returns array or null if no memory sensors discovered.
     */
    public function latestMemory(): ?array
    {
        $sensors = $this->snmpSensors()
            ->whereIn('sensor_group', ['memory', 'memory_used', 'memory_total',
                                       'memory_cached', 'memory_buffers', 'memory_shared', 'virtual_used'])
            ->with('latestMetric')
            ->get()
            ->groupBy('sensor_group');

        if ($sensors->isEmpty()) {
            // Try by name keyword
            $sensors = $this->snmpSensors()
                ->where(fn($q) => $q->where('name', 'like', '%memory%')
                                    ->orWhere('name', 'like', '%swap%'))
                ->with('latestMetric')
                ->get()
                ->groupBy(fn($s) => str_contains(strtolower($s->name), 'swap') ? 'virtual_used' : 'memory_used');

            if ($sensors->isEmpty()) {
                return null;
            }
        }

        $val = fn($group) => $sensors->get($group)?->first()?->latestMetric?->value;

        return [
            'physical_used'  => $val('memory_used')  ?? $val('memory'),
            'physical_total' => $val('memory_total'),
            'virtual_used'   => $val('virtual_used'),
            'cached'         => $val('memory_cached'),
            'buffers'        => $val('memory_buffers'),
            'shared'         => $val('memory_shared'),
        ];
    }

    /**
     * Get toner levels for printer-type devices.
     * Returns ['K'=>int|null, 'C'=>int|null, 'M'=>int|null, 'Y'=>int|null]
     */
    public function tonerLevels(): array
    {
        $sensors = $this->snmpSensors()
            ->where(fn($q) => $q->where('sensor_group', 'like', 'toner%')
                                ->orWhere('name', 'like', '%toner%')
                                ->orWhere('name', 'like', '%ink%'))
            ->with('latestMetric')
            ->get();

        $find = function (string ...$keywords) use ($sensors) {
            foreach ($sensors as $s) {
                $haystack = strtolower($s->name . ' ' . $s->sensor_group);
                foreach ($keywords as $kw) {
                    if (str_contains($haystack, $kw)) {
                        return $s->latestMetric ? (int) $s->latestMetric->value : null;
                    }
                }
            }
            return null;
        };

        return [
            'K' => $find('black', ' k ', 'toner_k', 'bk'),
            'C' => $find('cyan',  'toner_c'),
            'M' => $find('magenta', 'toner_m'),
            'Y' => $find('yellow',  'toner_y'),
        ];
    }

    /**
     * Determine whether this host is a printer.
     */
    public function isPrinter(): bool
    {
        return in_array($this->type, ['printer', 'mfp'])
            || in_array($this->discovered_type, ['printer', 'mfp', 'ricoh', 'hp-printer'])
            || $this->snmpSensors()
                    ->where(fn($q) => $q->where('sensor_group', 'like', 'toner%')
                                        ->orWhere('name', 'like', '%toner%'))
                    ->exists();
    }

    /**
     * Get interfaces grouped by interface_index for the port list.
     * Returns collection of SnmpSensor groups.
     */
    public function interfaceSensors(): \Illuminate\Support\Collection
    {
        return $this->snmpSensors()
            ->whereIn('sensor_group', ['interface_traffic', 'interface_status', 'interface_duplex'])
            ->with('latestMetric')
            ->get()
            ->groupBy('interface_index');
    }
}
