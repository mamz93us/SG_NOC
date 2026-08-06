<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
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

    /**
     * Outcome of the last handle() run, for callers that dispatch this job
     * synchronously (the "Discover" button) and need to report back to the user.
     * dispatchSync runs this very instance, so the caller sees these writes.
     *
     * @var array{ok: bool, reason: ?string, type: ?string, sensors: int}
     */
    public array $outcome = ['ok' => false, 'reason' => 'Discovery did not run.', 'type' => null, 'sensors' => 0];

    public function __construct(public MonitoredHost $host) {}

    public function handle(): void
    {
        if (! $this->host->snmp_enabled) {
            $this->outcome = [
                'ok' => false,
                'reason' => 'SNMP is disabled for this host — enable it in the host settings first.',
                'type' => null,
                'sensors' => 0,
            ];
            Log::warning("DiscoverSnmpDeviceJob: skipped, snmp_enabled is off for {$this->host->ip}");

            return;
        }

        if (! SnmpClient::isSnmpExtensionLoaded()) {
            Log::warning('DiscoverSnmpDeviceJob: PHP SNMP extension not loaded — will attempt CLI fallback.', [
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
                $port = $this->host->snmp_port ?? 161;

                $this->outcome = [
                    'ok' => false,
                    'reason' => "No SNMP response from {$this->host->ip}:{$port} ({$this->host->snmp_version}). "
                        .'Check the community string, that the device permits SNMP from this server, and that UDP '
                        .$port.' is reachable.',
                    'type' => null,
                    'sensors' => 0,
                ];

                Log::error("SNMP Discovery failed: No response from {$this->host->ip} for basic OIDs (sysDescr, sysName). Check community string or host availability.", [
                    'community' => $communityUsed,
                    'port' => $port,
                    'version' => $this->host->snmp_version,
                    'cli_fallback' => $client->isCliMode(),
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
                    'sensor_group' => 'system',
                ]
            );

            $sysObjectID = $client->get('1.3.6.1.2.1.1.2.0');

            // --- Vendor Detection via OS Factory (LibreNMS-inspired) ---
            $sysObjectIDStr = is_string($sysObjectID) ? $this->normalizeOid($sysObjectID) : '';
            $os = OsFactory::make($this->host, $client, $sysDescr, $sysObjectIDStr);

            $this->host->type = $os->hostType();
            $this->host->discovered_type = $os->discoveredType();

            // Create vendor-specific sensors
            $os->discoverSensors();

            // Post-discovery actions (VPN walk, UCM extension walk, ARP table, etc.)
            $os->postDiscover();

            $this->host->save();

            $this->outcome = [
                'ok' => true,
                'reason' => null,
                'type' => $os->discoveredType(),
                'sensors' => $this->host->snmpSensors()->count(),
            ];

            Log::info("SNMP Discovery completed for {$this->host->ip}", [
                'discovered_type' => $os->discoveredType(),
                'sysName' => $sysName,
                'sysDescr' => $sysDescr,
                'sensors' => $this->outcome['sensors'],
            ]);

            // Fire Interface Discovery automatically
            DiscoverSnmpInterfacesJob::dispatchSync($this->host);

        } catch (\Exception $e) {
            $this->outcome = [
                'ok' => false,
                'reason' => 'Discovery failed: '.$e->getMessage(),
                'type' => null,
                'sensors' => 0,
            ];

            Log::error('DiscoverSnmpDeviceJob failed', [
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
        if (! $value || $value === false) {
            return null;
        }
        $value = preg_replace('/^[a-zA-Z]+:\s*/', '', $value);

        return trim(trim($value, '"'));
    }

    /**
     * sysObjectID comes back from net-snmp with a type prefix and a *symbolic*
     * root — "OID: iso.3.6.1.4.1.12356.101.1.644". Every OS::detect() matches on
     * the numeric enterprise arc, so translate "iso." back to "1." or vendor
     * detection silently falls through to GenericOS.
     */
    protected function normalizeOid(string|false|null $value): string
    {
        $oid = $this->cleanString($value) ?? '';

        if (str_starts_with($oid, 'iso.')) {
            $oid = '1.'.substr($oid, 4);
        }

        return ltrim($oid, '.');
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
