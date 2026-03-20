<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Models\SnmpSensor;
use App\Polling\OS\OsFactory;
use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverSnmpDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public MonitoredHost $host)
    {
    }

    public function handle(): void
    {
        if (!$this->host->snmp_enabled) {
            return;
        }

        if (!SnmpClient::isSnmpExtensionLoaded()) {
            Log::warning("DiscoverSnmpDeviceJob: PHP SNMP extension not loaded — will attempt CLI fallback.", [
                'host' => $this->host->ip,
            ]);
        }

        $client = null;
        try {
            Log::info("Starting SNMP Discovery for {$this->host->ip}");
            $client = new SnmpClient($this->host);

            // Try to get basic info
            $sysDescrRaw = $client->get('1.3.6.1.2.1.1.1.0');
            $sysNameRaw = $client->get('1.3.6.1.2.1.1.5.0');

            if ($sysDescrRaw === false && $sysNameRaw === false) {
                $communityUsed = $this->host->snmp_community; // This calls the accessor
                Log::error("SNMP Discovery failed: No response from {$this->host->ip} for basic OIDs (sysDescr, sysName). Check community string or host availability.", [
                    'community' => $communityUsed,
                    'port' => $this->host->snmp_port ?? 161,
                    'version' => $this->host->snmp_version,
                ]);
                return;
            }

            Log::info("SNMP Discovery: Raw responses from {$this->host->ip}", [
                'sysName' => $sysNameRaw,
                'sysDescr' => $sysDescrRaw,
                'port' => $this->host->snmp_port ?? 161,
                'version' => $this->host->snmp_version,
            ]);

            $sysName = $this->cleanString($sysNameRaw);
            $sysDescr = $this->cleanString($sysDescrRaw);

            if ($sysName) {
                $this->host->name = $sysName;
            }

            // Sync Uptime: Try hrSystemUptime (.1.3.6.1.2.1.25.1.1.0) first as it matches actual system uptime better.
            // Fallback to sysUpTime (.1.3.6.1.2.1.1.3.0) which is time since SNMP service start.
            $testHrUptime = $client->get('1.3.6.1.2.1.25.1.1.0');
            $uptimeOid = ($testHrUptime !== false) ? '1.3.6.1.2.1.25.1.1.0' : '1.3.6.1.2.1.1.3.0';
            
            // Re-use or create the System Uptime sensor
            $this->host->snmpSensors()->updateOrCreate(
                ['name' => 'System Uptime'],
                [
                    'oid' => $uptimeOid,
                    'data_type' => 'uptime',
                    'poll_interval' => 60,
                    'graph_enabled' => true,
                    'sensor_group' => 'system'
                ]
            );

            $sysObjectID = $client->get('1.3.6.1.2.1.1.2.0');

            // --- Vendor Detection via OS Factory (LibreNMS-inspired) ---
            $sysObjectIDStr = is_string($sysObjectID) ? $this->cleanString($sysObjectID) ?? '' : '';
            $os = OsFactory::make($this->host, $client, $sysDescr, $sysObjectIDStr);

            $this->host->type           = $os->hostType();
            $this->host->discovered_type = $os->discoveredType();

            // Create vendor-specific sensors
            $os->discoverSensors();

            // Post-discovery actions (VPN walk, UCM extension walk, ARP table, etc.)
            $os->postDiscover();

            $this->host->save();

            Log::info("SNMP Discovery completed for {$this->host->ip}", [
                'discovered_type' => $os->discoveredType(),
                'sysName' => $sysName,
            ]);

            // Fire Interface Discovery automatically
            DiscoverSnmpInterfacesJob::dispatchSync($this->host);

        } catch (\Exception $e) {
            Log::error("DiscoverSnmpDeviceJob failed", [
                'host' => $this->host->ip,
                'port' => $this->host->snmp_port ?? 161,
                'version' => $this->host->snmp_version,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $client?->close();
        }
    }

    protected function cleanString(string|false|null $value): ?string
    {
        if (!$value || $value === false) return null;
        $value = preg_replace('/^[a-zA-Z]+:\s*/', '', $value);
        return trim(trim($value, '"'));
    }

    protected function createSensor(
        string $name,
        string $oid,
        string $dataType,
        ?string $unit = null,
        ?float $warn = null,
        ?float $crit = null,
        ?string $sensorGroup = null
    ): void {
        $this->host->snmpSensors()->updateOrCreate(
            ['oid' => $oid],
            [
                'name' => $name,
                'data_type' => $dataType,
                'unit' => $unit,
                'poll_interval' => 60,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'graph_enabled' => true,
                'sensor_group' => $sensorGroup,
            ]
        );
    }
}
