<?php

namespace App\Polling\OS;

use App\Models\MonitoredHost;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Facades\Log;

/**
 * BaseOS — Abstract base class for vendor-specific SNMP polling modules.
 *
 * Inspired by LibreNMS's OS class architecture.
 * Each vendor class overrides discoverSensors() to create the appropriate sensors
 * for that device type, using the shared createSensor() helper.
 */
abstract class BaseOS
{
    public function __construct(
        protected MonitoredHost $host,
        protected SnmpClient $client
    ) {}

    /**
     * Return the discovered_type string for this OS (e.g. 'cisco', 'sophos').
     */
    abstract public function discoveredType(): string;

    /**
     * Set the host type (switch / firewall / printer / server / generic).
     */
    abstract public function hostType(): string;

    /**
     * Detect whether the given sysDescr/sysObjectID matches this OS class.
     */
    abstract public static function detect(string $sysDescr, string $sysObjectID): bool;

    /**
     * Discover and create/update sensors for this device.
     * Called during discovery phase (runs infrequently).
     */
    abstract public function discoverSensors(): void;

    /**
     * Optional: post-discovery actions (e.g. walk VPN tunnels, UCM extensions).
     * Override in subclasses that need it.
     */
    public function postDiscover(): void {}

    // ─── Helpers ────────────────────────────────────────────────────────────

    protected function createSensor(
        string $name,
        string $oid,
        string $dataType,
        ?string $unit = null,
        ?float $warn = null,
        ?float $crit = null,
        ?string $sensorGroup = null,
        ?string $description = null
    ): void {
        $this->host->snmpSensors()->updateOrCreate(
            ['oid' => $oid],
            [
                'name'               => $name,
                'data_type'          => $dataType,
                'unit'               => $unit,
                'poll_interval'      => 60,
                'warning_threshold'  => $warn,
                'critical_threshold' => $crit,
                'graph_enabled'      => true,
                'sensor_group'       => $sensorGroup,
                'description'        => $description,
                'status'             => 'active',
            ]
        );
    }

    protected function snmpGet(string $oid): string|false
    {
        return $this->client->get($oid);
    }

    protected function snmpWalk(string $oid): array|false
    {
        return $this->client->walk($oid);
    }

    protected function cleanString(string|false|null $value): ?string
    {
        if (!$value || $value === false) return null;
        $value = preg_replace('/^[a-zA-Z]+:\s*/', '', $value);
        return trim(trim($value, '"'));
    }

    protected function log(string $message, array $context = []): void
    {
        Log::info("[OS:{$this->discoveredType()}] {$message}", array_merge(
            ['host' => $this->host->ip],
            $context
        ));
    }
}
