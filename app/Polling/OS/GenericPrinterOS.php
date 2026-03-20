<?php

namespace App\Polling\OS;

/**
 * Generic Printer OS — Standard Printer-MIB (RFC 3805) sensors.
 * Used for HP LaserJet, Lexmark, and any printer not matched by a specific vendor class.
 */
class GenericPrinterOS extends BaseOS
{
    public function discoveredType(): string { return 'printer'; }
    public function hostType(): string       { return 'printer'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Printer') !== false
            || stripos($sysDescr, 'HP LaserJet') !== false
            || stripos($sysDescr, 'Lexmark') !== false
            || stripos($sysDescr, 'Canon') !== false
            || stripos($sysDescr, 'Konica') !== false
            || stripos($sysDescr, 'Xerox') !== false
            || stripos($sysDescr, 'Kyocera') !== false;
    }

    public function discoverSensors(): void
    {
        // Standard Printer-MIB sensors (RFC 3805)
        $this->createSensor('Page Count',      '1.3.6.1.2.1.43.10.2.1.4.1.1', 'counter', 'pages', null, null, 'Printer',
            'Total page count from prtMarkerLifeCount');
        $this->createSensor('Printer Status',  '1.3.6.1.2.1.25.3.5.1.1.1',    'gauge', null, null, null, 'Printer',
            '3=idle, 4=printing, 5=warmup');
        $this->createSensor('Black Toner',     '1.3.6.1.2.1.43.11.1.1.9.1.1', 'gauge', '%', 20, 5, 'Toner',
            'prtMarkerSuppliesLevel index 1');

        $this->log('Generic printer sensors discovered');
    }
}
