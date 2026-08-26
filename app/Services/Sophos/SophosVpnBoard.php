<?php

namespace App\Services\Sophos;

use App\Models\SnmpSensor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Assembles the Sophos site-to-site VPN board from SNMP sensors.
 *
 * Sophos publishes two sensors per IPsec tunnel:
 *
 *   "VPN: {name} - Active"      2 = enabled, 0 = administratively disabled
 *   "VPN: {name} - Connection"  1 = connected, 0 = disconnected
 *
 * The dashboard used to read only the Connection half, which made a tunnel that
 * someone had deliberately switched off -- a backup link held in reserve --
 * render as a red "down", identical to one that had actually failed. Pairing the
 * two halves is what lets the board say "disabled" and mean it.
 *
 * Rows are also filtered by monitoring state: sensors discovery no longer sees
 * are retired and hidden, and an operator can mute a tunnel that is enabled on
 * the firewall but should not be paging anyone.
 */
class SophosVpnBoard
{
    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    /** Switched off on the firewall itself. */
    public const STATUS_DISABLED = 'disabled';

    /** Enabled on the firewall, but muted here by an operator. */
    public const STATUS_MUTED = 'muted';

    /** No reading yet, or the poller has not reported one. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Worst first: the board exists so what needs attention is at the top. */
    private const RANK = [
        self::STATUS_DOWN => 0,
        self::STATUS_UNKNOWN => 1,
        self::STATUS_UP => 2,
        self::STATUS_DISABLED => 3,
        self::STATUS_MUTED => 4,
    ];

    /**
     * Every tunnel, one row each.
     *
     * @param  bool  $includeRetired  show tunnels no longer on any firewall
     * @param  int|null  $hostId  limit to one monitored host
     * @return Collection<int, array>
     */
    public function tunnels(bool $includeRetired = false, ?int $hostId = null): Collection
    {
        if (! Schema::hasTable('snmp_sensors') || ! Schema::hasColumn('snmp_sensors', 'retired_at')) {
            return collect();
        }

        // latestMetric() is a latestOfMany relation: one subquery for every
        // sensor, rather than the per-sensor query the dashboard used to run.
        $sensors = SnmpSensor::with(['host.branch', 'latestMetric'])
            ->where('sensor_group', 'VPN')
            ->when(! $includeRetired, fn ($q) => $q->whereNull('retired_at'))
            ->when($hostId !== null, fn ($q) => $q->where('host_id', $hostId))
            ->get();

        return $sensors
            // Pair the Active and Connection halves back up per tunnel. Keyed by
            // host as well as name because two firewalls can both have a tunnel
            // called "Backup".
            ->groupBy(fn (SnmpSensor $s) => $s->host_id.'|'.($s->vpnTunnelName() ?? $s->name))
            ->map(fn (Collection $pair) => $this->row($pair))
            ->filter()
            ->sortBy([
                fn ($a, $b) => (self::RANK[$a['status']] ?? 9) <=> (self::RANK[$b['status']] ?? 9),
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /** @param  Collection<int, SnmpSensor>  $pair */
    private function row(Collection $pair): ?array
    {
        $connection = $pair->first(fn (SnmpSensor $s) => $s->isVpnConnectionSensor());
        $active = $pair->first(fn (SnmpSensor $s) => $s->isVpnActiveSensor());

        // The Connection sensor is the one that carries the operational state and
        // the one an operator toggles; a stray Active sensor on its own is not a
        // tunnel we can report on.
        if (! $connection) {
            return null;
        }

        $sensor = $connection;
        $host = $sensor->host;

        return [
            'sensor_id' => $sensor->id,
            'name' => $sensor->vpnTunnelName() ?? $sensor->name,
            'status' => $this->status($connection, $active),
            'firewall' => $host?->name ?: '-',
            'firewall_ip' => $host?->ip ?: '-',
            'host_id' => $sensor->host_id,
            'branch' => $host?->branch?->name ?: 'No branch',
            'monitor_enabled' => (bool) $sensor->monitor_enabled,
            'retired' => $sensor->isRetired(),
            'retired_at' => $sensor->retired_at?->toIso8601String(),
            'last_checked' => $sensor->latestMetric?->recorded_at?->diffForHumans()
                ?: ($sensor->last_recorded_at?->diffForHumans() ?: '-'),
        ];
    }

    private function status(SnmpSensor $connection, ?SnmpSensor $active): string
    {
        // An operator mute wins over everything: they have said they do not want
        // to hear about this tunnel, and a muted tunnel that is also down should
        // still read as muted rather than shouting.
        if (! $connection->monitor_enabled) {
            return self::STATUS_MUTED;
        }

        // Administratively disabled on the firewall. Sophos reports 2 for
        // enabled, not 1 -- treat anything that is not a positive reading as
        // disabled only when we actually have a reading to go on.
        $activeValue = $active?->latestMetric?->value;
        if ($active && $activeValue !== null && (float) $activeValue <= 0) {
            return self::STATUS_DISABLED;
        }

        $value = $connection->latestMetric?->value;

        if ($value === null) {
            return self::STATUS_UNKNOWN;
        }

        return (float) $value >= 1.0 ? self::STATUS_UP : self::STATUS_DOWN;
    }

    /**
     * Counts for the panel header.
     *
     * @param  Collection<int, array>  $tunnels
     * @return array<string, int>
     */
    public function summary(Collection $tunnels): array
    {
        return [
            'up' => $tunnels->where('status', self::STATUS_UP)->count(),
            'down' => $tunnels->where('status', self::STATUS_DOWN)->count(),
            'disabled' => $tunnels->where('status', self::STATUS_DISABLED)->count(),
            'muted' => $tunnels->where('status', self::STATUS_MUTED)->count(),
            'unknown' => $tunnels->where('status', self::STATUS_UNKNOWN)->count(),
            'retired' => $tunnels->where('retired', true)->count(),
        ];
    }

    /** How many retired VPN sensors are hidden right now. */
    public function retiredCount(?int $hostId = null): int
    {
        if (! Schema::hasTable('snmp_sensors') || ! Schema::hasColumn('snmp_sensors', 'retired_at')) {
            return 0;
        }

        return SnmpSensor::where('sensor_group', 'VPN')
            ->whereNotNull('retired_at')
            ->when($hostId !== null, fn ($q) => $q->where('host_id', $hostId))
            // Count tunnels, not sensors: each has an Active and a Connection.
            ->get()
            ->groupBy(fn (SnmpSensor $s) => $s->host_id.'|'.($s->vpnTunnelName() ?? $s->name))
            ->count();
    }
}
