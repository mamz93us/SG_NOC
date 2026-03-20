<?php

namespace App\Polling\OS;

class GenericOS extends BaseOS
{
    public function discoveredType(): string { return 'generic'; }
    public function hostType(): string       { return 'generic'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return true; // Fallback — always matches
    }

    public function discoverSensors(): void
    {
        // Only the uptime sensor is universally available via SNMPv2-MIB
        // Interface discovery handles the rest via DiscoverSnmpInterfacesJob
        $this->log('Generic OS — no additional sensors created');
    }
}
