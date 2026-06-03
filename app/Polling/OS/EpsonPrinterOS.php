<?php

namespace App\Polling\OS;

use App\Polling\OS\Concerns\DiscoversStandardPrinterMib;

/**
 * Epson Printer OS — Epson inkjet / laser devices.
 *
 * Epson exposes consumables and counters through the standard Printer MIB
 * (RFC 3805), so discovery is handled by DiscoversStandardPrinterMib (it walks
 * the supply / tray tables). Ink level is reported 0-100 on Epson devices and
 * stored as data_type='toner_gauge'.
 *
 * Enterprise OID: 1.3.6.1.4.1.1248 (SEIKO EPSON).
 */
class EpsonPrinterOS extends BaseOS
{
    use DiscoversStandardPrinterMib;

    public function discoveredType(): string
    {
        return 'epson_printer';
    }

    public function hostType(): string
    {
        return 'printer';
    }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'EPSON') !== false
            || stripos($sysDescr, 'Seiko Epson') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.1248');
    }

    public function discoverSensors(): void
    {
        $this->discoverStandardPrinterMib();
        $this->log('Epson printer sensors discovered');
    }
}
