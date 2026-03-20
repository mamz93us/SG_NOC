<?php

namespace App\Polling\OS;

class GrandstreamOS extends BaseOS
{
    public function discoveredType(): string { return 'grandstream'; }
    public function hostType(): string       { return 'server'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Grandstream') !== false
            || stripos($sysDescr, 'UCM') !== false
            || str_contains($sysObjectID, '12581');
    }

    public function discoverSensors(): void
    {
        // GS-UCM63XX-SNMP-MIB system sensors
        $this->createSensor('Concurrent Calls', '1.3.6.1.4.1.12581.2.2.9.0', 'gauge', 'calls', null, null, 'system');
        $this->createSensor('CPU Usage',         '1.3.6.1.4.1.12581.2.2.8.0', 'gauge', '%', 85, 95, 'system');
        $this->createSensor('Memory Usage',      '1.3.6.1.4.1.12581.2.2.7.0', 'gauge', '%', 85, 95, 'system');
        $this->createSensor('Disk Usage',        '1.3.6.1.4.1.12581.2.2.6.0', 'gauge', '%', 85, 95, 'system');
        $this->log('Grandstream UCM system sensors discovered');
    }

    public function postDiscover(): void
    {
        $this->discoverExtensions();
        $this->discoverTrunks();
    }

    protected function discoverExtensions(): void
    {
        $extNums = $this->snmpWalk('1.3.6.1.4.1.12581.2.4.1.1.2');
        if (!$extNums) return;

        $count = 0;
        foreach ($extNums as $fullOid => $extNumRaw) {
            $extNum = $this->cleanString($extNumRaw);
            if (preg_match('/\.(\d+)$/', $fullOid, $m)) {
                $index = $m[1];
                $this->createSensor("Ext {$extNum} - Status",
                    "1.3.6.1.4.1.12581.2.4.1.1.3.{$index}", 'boolean', null, null, null, 'Extensions');
                $count++;
            }
        }
        $this->log("Discovered {$count} extensions");
    }

    protected function discoverTrunks(): void
    {
        $trunkNames = $this->snmpWalk('1.3.6.1.4.1.12581.2.5.1.1.2');
        if (!$trunkNames) return;

        $count = 0;
        foreach ($trunkNames as $fullOid => $trunkNameRaw) {
            $trunkName = $this->cleanString($trunkNameRaw);
            if (preg_match('/\.(\d+)$/', $fullOid, $m)) {
                $index = $m[1];
                $this->createSensor("Trunk {$trunkName} - Status",
                    "1.3.6.1.4.1.12581.2.5.1.1.4.{$index}", 'boolean', null, null, null, 'Trunks');
                $count++;
            }
        }
        $this->log("Discovered {$count} trunks");
    }
}
