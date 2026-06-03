<?php

namespace App\Polling\OS;

/**
 * Epson Printer OS — Epson inkjet / laser devices.
 *
 * Epson exposes consumables and counters through the standard Printer MIB
 * (RFC 3805, OID 1.3.6.1.2.1.43.*) rather than a vendor-private toner tree like
 * Ricoh. Ink level (prtMarkerSuppliesLevel) is reported 0-100 on Epson devices,
 * so we store it as data_type='toner_gauge' (the collector clamps 0-100 and
 * alerts on the low side).
 *
 * Sensors are discovered dynamically by WALKING the supply / input tables, so we
 * pick up however many ink cartridges and trays a given model actually has — no
 * per-model MIB file upload required.
 *
 * Enterprise OID: 1.3.6.1.4.1.1248 (SEIKO EPSON).
 */
class EpsonPrinterOS extends BaseOS
{
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
        // ── Status + total page count (standard MIB) ────────────────────
        $this->createSensor('Printer Status', '1.3.6.1.2.1.25.3.5.1.1.1',
            'gauge', null, null, null, 'Printer', '3=idle, 4=printing, 5=warmup');

        $this->createSensor('Total Page Count', '1.3.6.1.2.1.43.10.2.1.4.1.1',
            'absolute_counter', 'pages', null, null, 'Counters', 'prtMarkerLifeCount');

        // ── Ink / toner cartridges (walk the supply table) ──────────────
        $this->discoverSupplies();

        // ── Paper input trays ───────────────────────────────────────────
        $this->discoverInputTrays();

        $this->log('Epson printer sensors discovered');
    }

    /**
     * Walk prtMarkerSuppliesDescription and create one gauge per supply, using
     * the device's own description as the sensor name (e.g. "Black", "Cyan",
     * "Maintenance Box"). Level OID is prtMarkerSuppliesLevel at the same index.
     */
    protected function discoverSupplies(): void
    {
        $descrs = $this->snmpWalk('1.3.6.1.2.1.43.11.1.1.6.1'); // prtMarkerSuppliesDescription

        if (! $descrs) {
            // Some models don't answer the description sub-tree; fall back to a
            // single black supply at index 1 so the page still shows something.
            $this->createSensor('Ink / Toner', '1.3.6.1.2.1.43.11.1.1.9.1.1',
                'toner_gauge', '%', 20, 5, 'Toner', 'prtMarkerSuppliesLevel index 1');

            return;
        }

        $count = 0;
        foreach ($descrs as $oid => $rawDescr) {
            $index = (int) substr($oid, strrpos($oid, '.') + 1);
            if ($index <= 0) {
                continue;
            }

            $descr = $this->cleanString($rawDescr) ?: "Supply {$index}";
            $low = strtolower($descr);

            // Waste/maintenance units are "fill" gauges, not ink — group them apart.
            $group = (str_contains($low, 'waste') || str_contains($low, 'maintenance') || str_contains($low, 'collector'))
                ? 'Consumables'
                : 'Toner';

            $this->createSensor(
                $descr,
                "1.3.6.1.2.1.43.11.1.1.9.1.{$index}", // prtMarkerSuppliesLevel
                'toner_gauge',
                '%',
                20,
                5,
                $group,
                "prtMarkerSuppliesLevel index {$index}"
            );
            $count++;
        }

        $this->log("Epson supplies discovered: {$count}");
    }

    /**
     * Walk prtInputName and create a level gauge per paper input tray.
     */
    protected function discoverInputTrays(): void
    {
        $names = $this->snmpWalk('1.3.6.1.2.1.43.8.2.1.13.1'); // prtInputName

        if (! $names) {
            return;
        }

        foreach ($names as $oid => $rawName) {
            $index = (int) substr($oid, strrpos($oid, '.') + 1);
            if ($index <= 0) {
                continue;
            }

            $name = $this->cleanString($rawName) ?: "Tray {$index}";

            $this->createSensor(
                "Tray: {$name}",
                "1.3.6.1.2.1.43.8.2.1.10.1.{$index}", // prtInputCurrentLevel
                'gauge',
                'sheets',
                null,
                null,
                'Paper',
                "prtInputCurrentLevel index {$index}"
            );
        }
    }
}
