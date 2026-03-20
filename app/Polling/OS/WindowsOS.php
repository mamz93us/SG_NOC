<?php

namespace App\Polling\OS;

class WindowsOS extends BaseOS
{
    public function discoveredType(): string { return 'windows'; }
    public function hostType(): string       { return 'server'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Windows') !== false
            || stripos($sysDescr, 'Microsoft') !== false;
    }

    public function discoverSensors(): void
    {
        // HOST-RESOURCES-MIB (available on Windows via SNMP service)
        $this->createSensor('System Uptime',
            '1.3.6.1.2.1.25.1.1.0', 'uptime', null, null, null, 'system',
            'System uptime from HOST-RESOURCES-MIB');

        // Windows-specific via HOST-RESOURCES
        $this->createSensor('Running Processes',
            '1.3.6.1.2.1.25.1.6.0', 'gauge', 'procs', 300, 500, 'system',
            'Number of running processes');

        $this->createSensor('CPU Load 1m',
            '1.3.6.1.2.1.25.3.3.1.2.1', 'gauge', '%', 85, 95, 'system',
            'hrProcessorLoad — 1-minute CPU average');

        $this->log('Windows sensors discovered');
    }
}
