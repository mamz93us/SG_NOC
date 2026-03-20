<?php

namespace App\Polling\OS;

class HpArubaOS extends BaseOS
{
    public function discoveredType(): string { return 'hp_aruba'; }
    public function hostType(): string       { return 'switch'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Aruba') !== false
            || stripos($sysDescr, 'HPE') !== false
            || stripos($sysDescr, 'ProCurve') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.11.');
    }

    public function discoverSensors(): void
    {
        // HP/Aruba switches — ENTITY-MIB or HP private MIB
        // Fan status: entPhysicalTable or hpicfFanTable
        $this->createSensor('CPU Utilization',
            '1.3.6.1.4.1.11.2.14.11.5.1.9.6.1.0', 'gauge', '%', 80, 95, 'system',
            'HP ICFAM CPU utilization');

        $this->createSensor('Memory Used %',
            '1.3.6.1.4.1.11.2.14.11.5.1.1.2.1.3.1', 'gauge', '%', 80, 95, 'memory',
            'HP switch memory utilization');

        $this->log('HP/Aruba sensors discovered');
    }
}
