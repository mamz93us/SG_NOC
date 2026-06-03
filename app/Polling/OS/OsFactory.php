<?php

namespace App\Polling\OS;

use App\Models\MonitoredHost;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Facades\Log;

/**
 * OsFactory — Resolves the correct OS class for a given MonitoredHost.
 *
 * Inspired by LibreNMS's OS factory pattern.
 * Priority order matters: more specific vendors must come before generic ones.
 */
class OsFactory
{
    /**
     * Ordered list of OS classes to try, most specific first.
     * The first class whose detect() returns true is used.
     * GenericOS must always be last (it always returns true).
     */
    private const OS_CLASSES = [
        RicohPrinterOS::class,    // Ricoh/NRG/Lanier printers (before generic printer)
        EpsonPrinterOS::class,    // Epson inkjet/laser (standard Printer MIB, dynamic supplies)
        CanonPrinterOS::class,    // Canon imageRUNNER/i-SENSYS/PIXMA (standard MIB, dynamic supplies)
        GenericPrinterOS::class,  // HP LaserJet, Lexmark, Xerox, Kyocera
        CiscoOS::class,           // Cisco IOS / NX-OS
        SophosOS::class,          // Sophos SFOS firewall
        GrandstreamOS::class,     // Grandstream UCM PBX
        HpArubaOS::class,         // HP / Aruba / ProCurve switches
        LinuxOS::class,           // Linux (NET-SNMP)
        WindowsOS::class,         // Windows SNMP service
        GenericOS::class,         // Fallback
    ];

    /**
     * Create the appropriate OS instance for the given host.
     *
     * @param  string  $sysDescr  Raw sysDescr string from SNMP
     * @param  string  $sysObjectID  Raw sysObjectID string from SNMP
     */
    public static function make(
        MonitoredHost $host,
        SnmpClient $client,
        string $sysDescr,
        string $sysObjectID = ''
    ): BaseOS {
        foreach (self::OS_CLASSES as $class) {
            if ($class::detect($sysDescr, $sysObjectID)) {
                Log::info("[OsFactory] Matched {$class} for host {$host->ip}");

                return new $class($host, $client);
            }
        }

        // Should never reach here since GenericOS always matches
        return new GenericOS($host, $client);
    }

    /**
     * Resolve OS class by stored discovered_type string (for re-running without full detect).
     */
    public static function makeByType(
        MonitoredHost $host,
        SnmpClient $client,
        string $discoveredType
    ): BaseOS {
        $map = [
            'ricoh_printer' => RicohPrinterOS::class,
            'epson_printer' => EpsonPrinterOS::class,
            'canon_printer' => CanonPrinterOS::class,
            'printer' => GenericPrinterOS::class,
            'cisco' => CiscoOS::class,
            'sophos' => SophosOS::class,
            'grandstream' => GrandstreamOS::class,
            'hp_aruba' => HpArubaOS::class,
            'linux' => LinuxOS::class,
            'windows' => WindowsOS::class,
            'generic_switch' => GenericOS::class,
        ];

        $class = $map[$discoveredType] ?? GenericOS::class;

        return new $class($host, $client);
    }
}
