<?php

namespace App\Polling\OS;

class CiscoOS extends BaseOS
{
    public function discoveredType(): string { return 'cisco'; }
    public function hostType(): string       { return 'switch'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Cisco') !== false
            || stripos($sysDescr, 'IOS') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.9.');
    }

    public function discoverSensors(): void
    {
        // CPU — CISCO-PROCESS-MIB: cpmCPUTotal5minRev
        $this->createSensor('CPU Usage (5m)', '1.3.6.1.4.1.9.9.109.1.1.1.1.8.1', 'gauge', '%', 85, 95, 'system',
            '5-minute CPU utilization percentage');

        // Memory — CISCO-MEMORY-POOL-MIB: ciscoMemoryPoolFree
        $this->createSensor('Free Memory', '1.3.6.1.4.1.9.9.48.1.1.1.6.1', 'gauge', 'bytes', null, null, 'system',
            'Free processor memory pool bytes');

        // Temperature (if available) — CISCO-ENVMON-MIB
        $this->createSensor('Inlet Temperature', '1.3.6.1.4.1.9.9.13.1.3.1.3.1', 'gauge', '°C', 50, 60, 'environment',
            'Chassis inlet temperature');

        $this->log('Cisco sensors discovered');
    }
}
