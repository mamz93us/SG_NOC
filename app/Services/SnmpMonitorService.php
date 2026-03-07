<?php

namespace App\Services;

use App\Models\MonitoredHost;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Facades\Log;

class SnmpMonitorService
{
    public function pollAll(): void
    {
        $hosts = MonitoredHost::where('snmp_enabled', true)->get();

        foreach ($hosts as $host) {
            $this->pollHost($host);
        }
    }

    public function pollHost(MonitoredHost $host): array
    {
        if (!$host->snmp_enabled) {
            return ['status' => 'error', 'message' => 'SNMP is not enabled for this host.'];
        }

        try {
            $client = new SnmpClient($host);
            $client->connect();

            $results = [];

            $uptime = $client->get('.1.3.6.1.2.1.1.3.0');
            if ($uptime) {
                $results['uptime'] = $this->parseValue($uptime);
            }

            $sensors = $host->snmpSensors;
            foreach ($sensors as $sensor) {
                $val = $client->get($sensor->oid);
                if ($val) {
                    $results[$sensor->name ?: $sensor->oid] = $this->parseValue($val);
                }
            }

            $client->close();

            $host->update(['status' => 'up', 'last_checked_at' => now()]);
            return ['status' => 'success', 'data' => $results];

        } catch (\Exception $e) {
            Log::error("SnmpMonitorService: Poll failed for {$host->ip}", ['error' => $e->getMessage()]);
            $host->update(['status' => 'down', 'last_checked_at' => now()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function parseValue($value): float
    {
        if (preg_match('/(\d+(\.\d+)?)/', $value, $matches)) {
            return (float) $matches[1];
        }
        return 0;
    }
}
