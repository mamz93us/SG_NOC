<?php

namespace App\Polling\OS;

/**
 * Ricoh Printer OS — Ricoh Private MIB (enterprise OID 1.3.6.1.4.1.367)
 * Also covers NRG and Lanier (rebranded Ricoh devices).
 *
 * Ricoh Private MIB structure:
 *   - Toner names:    1.3.6.1.4.1.367.3.2.1.2.24.1.1.3.{index}
 *   - Toner levels:   1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.{index}
 *   - Page counters:  1.3.6.1.4.1.367.3.2.1.2.19.*
 *
 * Important: page counter OIDs use data_type='absolute_counter' so the
 * collector stores the raw cumulative value (e.g. 827,244 total pages) rather
 * than computing a rate.  Toner OIDs use data_type='toner_gauge' so the
 * collector knows to normalise Ricoh's negative-value encoding
 * (Ricoh reports full toner as -100; we convert to 100%).
 */
class RicohPrinterOS extends BaseOS
{
    public function discoveredType(): string { return 'ricoh_printer'; }
    public function hostType(): string       { return 'printer'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'RICOH') !== false
            || stripos($sysDescr, 'Ricoh') !== false
            || stripos($sysDescr, 'NRG') !== false
            || stripos($sysDescr, 'Lanier') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.367');
    }

    public function discoverSensors(): void
    {
        // ── Printer Status (standard MIB) ───────────────────────────────
        $this->createSensor('Printer Status', '1.3.6.1.2.1.25.3.5.1.1.1',
            'gauge', null, null, null, 'Printer', '3=idle, 4=printing, 5=warmup');

        // ── Toner levels (Ricoh Private MIB) ────────────────────────────
        // data_type='toner_gauge': collector normalises Ricoh negative encoding
        // warning_threshold / critical_threshold = LOW-side thresholds (alert when ≤ threshold)
        $this->createSensor('Ricoh Black Toner',   '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.1', 'toner_gauge', '%', 20, 5,  'Toner');
        $this->createSensor('Ricoh Cyan Toner',    '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.2', 'toner_gauge', '%', 20, 5,  'Toner');
        $this->createSensor('Ricoh Magenta Toner', '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.3', 'toner_gauge', '%', 20, 5,  'Toner');
        $this->createSensor('Ricoh Yellow Toner',  '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.4', 'toner_gauge', '%', 20, 5,  'Toner');

        // ── Page counters (Ricoh Private MIB) ───────────────────────────
        // data_type='absolute_counter': store the raw cumulative page count, NOT a rate
        $this->createSensor('Total Counter', '1.3.6.1.4.1.367.3.2.1.2.19.1.0',       'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Print Counter', '1.3.6.1.4.1.367.3.2.1.2.19.2.0',       'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Fax Counter',   '1.3.6.1.4.1.367.3.2.1.2.19.3.0',       'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Copy Counter',  '1.3.6.1.4.1.367.3.2.1.2.19.4.0',       'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Color Pages',   '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.21',  'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Mono Pages',    '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.22',  'absolute_counter', 'pages', null, null, 'Counters');
        $this->createSensor('Scan Counter',  '1.3.6.1.4.1.367.3.2.1.2.19.5.1.9.27',  'absolute_counter', 'pages', null, null, 'Counters');

        // ── Paper tray ───────────────────────────────────────────────────
        $this->createSensor('Tray Status', '1.3.6.1.4.1.367.3.2.1.2.20.2.2.1.11.2', 'gauge', null, null, null, 'Paper');
        $this->createSensor('Tray Level',  '1.3.6.1.4.1.367.3.2.1.2.20.2.2.1.10.2', 'gauge', null, null, null, 'Paper');

        // ── Drum / consumables ───────────────────────────────────────────
        $this->createSensor('Drum Remaining', '1.3.6.1.4.1.367.3.2.1.2.24.1.1.5.5', 'toner_gauge', '%', 20, 5, 'Consumables');

        $this->log('Ricoh printer sensors discovered');
    }
}
