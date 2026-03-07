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
            $interfaces = $client->walk('1.3.6.1.2.1.2.2.1.2');

            if (!$interfaces) {
                Log::info("DiscoverSnmpInterfacesJob: No interfaces found for {$this->host->ip}");
                return;
            }

            $discoveredCount = 0;

            // Check if HC counters are supported (SNMP v2c/v3 usually)
            $hcSupported = false;
            
            if ($this->host->snmp_version !== 'v1') {
                // Check up to 5 interfaces to see if any support HC counters
                $checkCount = 0;
                foreach ($interfaces as $oid => $descr) {
                    $parts = explode('.', $oid);
                    $index = (int) end($parts);
                    
                    // Try to get ifHCInOctets for this index
                    $testVal = $client->get("1.3.6.1.2.1.31.1.1.1.6.{$index}");
                    if ($testVal !== false && $testVal !== null && $testVal !== '') {
                        $hcSupported = true;
                        break;
                    }
                    
                    $checkCount++;
                    if ($checkCount >= 5) break;
                }
            }

            foreach ($interfaces as $oid => $descr) {
                // Determine port index
                $parts = explode('.', $oid);
                $index = (int) end($parts);
                $cleanName = trim(trim($descr, '"'));

                // Skip loopback and null interfaces
                if (stripos($cleanName, 'loopback') !== false || stripos($cleanName, 'null') !== false) {
                    continue;
                }

                if ($hcSupported) {
                    // Traffic In (ifHCInOctets) .1.3.6.1.2.1.31.1.1.1.6
                    $this->createSensor(
                        $cleanName . ' - Traffic In',
                        "1.3.6.1.2.1.31.1.1.1.6.{$index}",
                        'counter',
                        'bytes/sec',
                        $index,
                        'interface_traffic'
                    );

                    // Traffic Out (ifHCOutOctets) .1.3.6.1.2.1.31.1.1.1.10
                    $this->createSensor(
                        $cleanName . ' - Traffic Out',
                        "1.3.6.1.2.1.31.1.1.1.10.{$index}",
                        'counter',
                        'bytes/sec',
                        $index,
                        'interface_traffic'
                    );
                } else {
                    // Fallback to 32-bit counters if HC not supported
                    // Traffic In (ifInOctets) .1.3.6.1.2.1.2.2.1.10
                    $this->createSensor(
                        $cleanName . ' - Traffic In',
                        "1.3.6.1.2.1.2.2.1.10.{$index}",
                        'counter',
                        'bytes/sec',
                        $index,
                        'interface_traffic'
                    );

                    // Traffic Out (ifOutOctets) .1.3.6.1.2.1.2.2.1.16
                    $this->createSensor(
                        $cleanName . ' - Traffic Out',
                        "1.3.6.1.2.1.2.2.1.16.{$index}",
                        'counter',
                        'bytes/sec',
                        $index,
                        'interface_traffic'
                    );
                }

                // Port Status (ifOperStatus) .1.3.6.1.2.1.2.2.1.8
                $this->createSensor(
                    $cleanName . ' - Status',
                    "1.3.6.1.2.1.2.2.1.8.{$index}",
                    'boolean',
                    'status',
                    $index,
                    'interface_status'
                );

                $discoveredCount++;
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
        string $sensorGroup
    ): void {
        $this->host->snmpSensors()->firstOrCreate(
            ['oid' => $oid],
            [
                'name' => $name,
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
