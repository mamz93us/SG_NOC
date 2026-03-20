<?php

namespace App\Polling\OS;

class LinuxOS extends BaseOS
{
    public function discoveredType(): string { return 'linux'; }
    public function hostType(): string       { return 'server'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Linux') !== false;
    }

    public function discoverSensors(): void
    {
        // UCD-SNMP-MIB (NET-SNMP)
        $this->createSensor('Load Average 1m',  '1.3.6.1.4.1.2021.10.1.3.1', 'gauge', null, 2.0, 5.0, 'system',
            '1-minute load average');
        $this->createSensor('Load Average 5m',  '1.3.6.1.4.1.2021.10.1.3.2', 'gauge', null, 2.0, 5.0, 'system',
            '5-minute load average');
        $this->createSensor('CPU Idle',          '1.3.6.1.4.1.2021.11.11.0', 'gauge', '%', null, null, 'system',
            'CPU idle percentage (low = high CPU usage)');
        $this->createSensor('Memory Available',  '1.3.6.1.4.1.2021.4.11.0',  'gauge', 'KB', null, null, 'memory',
            'Available real memory in KB');
        $this->createSensor('Swap Used',         '1.3.6.1.4.1.2021.4.4.0',   'gauge', 'KB', null, null, 'memory',
            'Used swap space in KB');
        $this->createSensor('Total Processes',   '1.3.6.1.2.1.25.1.6.0',     'gauge', 'procs', 300, 500, 'system',
            'Total number of running processes');
        $this->log('Linux sensors discovered');
    }
}
