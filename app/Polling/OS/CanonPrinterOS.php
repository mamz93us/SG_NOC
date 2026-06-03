<?php

namespace App\Polling\OS;

use App\Polling\OS\Concerns\DiscoversStandardPrinterMib;

/**
 * Canon Printer OS — imageRUNNER / imageCLASS / i-SENSYS / PIXMA / MAXIFY.
 *
 * Canon devices expose toner and page counts through the standard Printer MIB
 * (RFC 3805), so discovery is handled by DiscoversStandardPrinterMib (it walks
 * the supply / tray tables dynamically). This sits before GenericPrinterOS so
 * Canons get full per-cartridge discovery instead of a single "Black Toner".
 *
 * Enterprise OID: 1.3.6.1.4.1.1602 (Canon Inc.).
 */
class CanonPrinterOS extends BaseOS
{
    use DiscoversStandardPrinterMib;

    public function discoveredType(): string
    {
        return 'canon_printer';
    }

    public function hostType(): string
    {
        return 'printer';
    }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Canon') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.1602');
    }

    public function discoverSensors(): void
    {
        $this->discoverStandardPrinterMib();
        $this->log('Canon printer sensors discovered');
    }
}
