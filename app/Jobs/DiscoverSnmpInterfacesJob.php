<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverSnmpInterfacesJob implements ShouldQueue
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
            Log::warning("DiscoverSnmpInterfacesJob: PHP SNMP extension not loaded — will attempt CLI fallback.", [
                'host' => $this->host->ip,
            ]);
        }

        $client = null;
        try {
            $client = new SnmpClient($this->host);
            $client->connect();
            $client->setOidOutputFormat(\SNMP_OID_OUTPUT_NUMERIC ?? 3);
            $client->setValueRetrieval(\SNMP_VALUE_PLAIN ?? 4);

            // Walk IF-MIB::ifDescr (.1.3.6.1.2.1.2.2.1.2)
            $ifDescrs = $client->walk('1.3.6.1.2.1.2.2.1.2');
            
            // Walk IF-MIB::ifType (.1.3.6.1.2.1.2.2.1.3) to filter physical ports
            $ifTypes = $client->walk('1.3.6.1.2.1.2.2.1.3') ?: [];

            // Try to walk IF-MIB::ifAlias (.1.3.6.1.2.1.31.1.1.1.18) for prettier names/descriptions
            $ifAliases = $client->walk('1.3.6.1.2.1.31.1.1.1.18') ?: [];
            
            // Try to walk ifHighSpeed (.1.3.6.1.2.1.31.1.1.1.15) for 64-bit speed info (mbps)
            $ifHighSpeeds = $client->walk('1.3.6.1.2.1.31.1.1.1.15') ?: [];

            if (!$ifDescrs) {
                Log::info("DiscoverSnmpInterfacesJob: No interfaces found for {$this->host->ip}");
                return;
            }

            $discoveredCount = 0;
            $discoveredIndices = [];

            // Check if HC counters are supported
            $hcSupported = false;
            if ($this->host->snmp_version !== 'v1') {
                foreach ($ifDescrs as $oid => $descr) {
                    $parts = explode('.', $oid);
                    $index = (int) end($parts);
                    $testVal = $client->get("1.3.6.1.2.1.31.1.1.1.6.{$index}");
                    if ($testVal !== false && $testVal !== null && $testVal !== '') {
                        $hcSupported = true;
                        break;
                    }
                    if (++$discoveredCount >= 5) break;
                }
                $discoveredCount = 0; // reset for actual count
            }

            foreach ($ifDescrs as $oid => $descr) {
                // Determine port index
                $parts = explode('.', $oid);
                $index = (int) end($parts);
                $cleanName = trim(trim($descr, '"'));

                // --- STRICT FILTERING ---
                $type = isset($ifTypes[$oid]) ? (int)$ifTypes[$oid] : 0;
                
                // Keep only physical-like types (6=ethernet, 7=ethernet, 117=gigabit)
                // Skip common virtual/software types: 24=loopback, 53=propVirtual, 131=tunnel, 135=l2vlan/l3ipvlan
                $isPhysical = in_array($type, [6, 7, 117]);
                
                // Fallback: if type is unknown or generic, filter by name
                $isVlan = stripos($cleanName, 'vlan') !== false || stripos($cleanName, 'V.') !== false;
                $isVirtual = stripos($cleanName, 'loopback') !== false || stripos($cleanName, 'null') !== false || stripos($cleanName, 'tunnel') !== false || stripos($cleanName, 'software') !== false || stripos($cleanName, 'virtual') !== false;

                if (!$isPhysical && ($isVlan || $isVirtual)) {
                    continue;
                }
                
                // Skip if it looks like a VLAN by type even if name is weird
                if (in_array($type, [135, 136, 161])) {
                    continue;
                }

                $alias = isset($ifAliases["1.3.6.1.2.1.31.1.1.1.18.{$index}"]) ? trim(trim($ifAliases["1.3.6.1.2.1.31.1.1.1.18.{$index}"], '"')) : null;
                $speedMbps = isset($ifHighSpeeds["1.3.6.1.2.1.31.1.1.1.15.{$index}"]) ? (int)$ifHighSpeeds["1.3.6.1.2.1.31.1.1.1.15.{$index}"] : 0;
                
                $description = $alias ?: $cleanName;
                if ($speedMbps > 0) {
                    $description .= " (" . ($speedMbps >= 1000 ? ($speedMbps/1000)."Gbps" : $speedMbps."Mbps") . ")";
                }

                if ($hcSupported) {
                    // Traffic In (ifHCInOctets)
                    $this->createSensor($cleanName . ' - Traffic In', "1.3.6.1.2.1.31.1.1.1.6.{$index}", 'counter', 'bytes/sec', $index, 'interface_traffic', $description);
                    // Traffic Out (ifHCOutOctets)
                    $this->createSensor($cleanName . ' - Traffic Out', "1.3.6.1.2.1.31.1.1.1.10.{$index}", 'counter', 'bytes/sec', $index, 'interface_traffic', $description);
                } else {
                    // Fallback to 32-bit
                    $this->createSensor($cleanName . ' - Traffic In', "1.3.6.1.2.1.2.2.1.10.{$index}", 'counter', 'bytes/sec', $index, 'interface_traffic', $description);
                    $this->createSensor($cleanName . ' - Traffic Out', "1.3.6.1.2.1.2.2.1.16.{$index}", 'counter', 'bytes/sec', $index, 'interface_traffic', $description);
                }

                // Port Status (ifOperStatus)
                $this->createSensor($cleanName . ' - Status', "1.3.6.1.2.1.2.2.1.8.{$index}", 'boolean', 'status', $index, 'interface_status', $description);

                $discoveredIndices[] = $index;
                $discoveredCount++;
            }

            // Cleanup: remove interface sensors that are no longer part of the physical set
            if (!empty($discoveredIndices)) {
                $this->host->snmpSensors()
                    ->whereIn('sensor_group', ['interface_traffic', 'interface_status'])
                    ->whereNotIn('interface_index', $discoveredIndices)
                    ->delete();
            }

            Log::info("DiscoverSnmpInterfacesJob completed for {$this->host->ip}", [
                'interfaces_found' => $discoveredCount,
                'port' => $this->host->snmp_port ?? 161,
            ]);

        } catch (\Exception $e) {
            Log::error("DiscoverSnmpInterfacesJob failed", [
                'host' => $this->host->ip,
                'port' => $this->host->snmp_port ?? 161,
                'version' => $this->host->snmp_version,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $client?->close();
        }
    }

    protected function createSensor(
        string $name,
        string $oid,
        string $dataType,
        string $unit,
        int $interfaceIndex,
        string $sensorGroup,
        ?string $description = null
    ): void {
        $this->host->snmpSensors()->updateOrCreate(
            ['oid' => $oid],
            [
                'name' => $name,
                'description' => $description,
                'data_type' => $dataType,
                'unit' => $unit,
                'poll_interval' => 60,
                'graph_enabled' => true,
                'interface_index' => $interfaceIndex,
                'sensor_group' => $sensorGroup,
            ]
        );
    }
}
