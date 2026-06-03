<?php

namespace App\Polling\OS\Concerns;

/**
 * Dynamic discovery of standard Printer-MIB (RFC 3805) sensors: printer status,
 * total page count, supplies (ink / toner / drum / waste) and paper input trays.
 *
 * It WALKS the device's own tables, so it adapts to however many cartridges and
 * trays a given model exposes — no per-model MIB file required. Any vendor whose
 * devices speak the standard Printer MIB (Epson, Canon, HP, Lexmark, Xerox,
 * Kyocera, Brother, …) can mix this in.
 *
 * Requires the host BaseOS helpers: createSensor(), snmpWalk(), cleanString(),
 * log().
 */
trait DiscoversStandardPrinterMib
{
    /**
     * Create the full set of standard Printer-MIB sensors for this device.
     */
    protected function discoverStandardPrinterMib(): void
    {
        $this->createSensor('Printer Status', '1.3.6.1.2.1.25.3.5.1.1.1',
            'gauge', null, null, null, 'Printer', '3=idle, 4=printing, 5=warmup');

        // Absolute cumulative page count (prtMarkerLifeCount), stored as-is.
        $this->createSensor('Total Page Count', '1.3.6.1.2.1.43.10.2.1.4.1.1',
            'absolute_counter', 'pages', null, null, 'Counters', 'prtMarkerLifeCount');

        $this->discoverPrinterSupplies();
        $this->discoverPrinterInputTrays();
    }

    /**
     * One gauge per supply, named from the device's own description
     * (prtMarkerSuppliesDescription), reading the matching level OID.
     */
    protected function discoverPrinterSupplies(): void
    {
        $descrs = $this->snmpWalk('1.3.6.1.2.1.43.11.1.1.6.1'); // prtMarkerSuppliesDescription

        if (! $descrs) {
            // Some models don't answer the description sub-tree; fall back to a
            // single supply at index 1 so the page still shows something.
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

            // Waste/maintenance units are "fill" gauges, not ink — group apart.
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

        $this->log("Standard printer supplies discovered: {$count}");
    }

    /**
     * One level gauge per paper input tray (prtInputName / prtInputCurrentLevel).
     */
    protected function discoverPrinterInputTrays(): void
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
