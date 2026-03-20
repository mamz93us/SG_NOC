<?php

namespace App\Polling\OS;

use App\Jobs\CollectArpTableJob;

class SophosOS extends BaseOS
{
    public function discoveredType(): string { return 'sophos'; }
    public function hostType(): string       { return 'firewall'; }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Sophos') !== false
            || stripos($sysDescr, 'SFOS') !== false
            || str_contains($sysObjectID, '2604');
    }

    public function discoverSensors(): void
    {
        // SOPHOS-FIREWALL-MIB
        $this->createSensor('Memory Usage',       '1.3.6.1.4.1.2604.5.1.2.4.2.0', 'gauge', '%', 80, 95, 'system');
        $this->createSensor('CPU Load',           '1.3.6.1.4.1.2604.5.1.2.6.0',   'gauge', '%', 80, 95, 'system');
        $this->createSensor('Disk Usage',         '1.3.6.1.4.1.2604.5.1.2.5.2.0', 'gauge', '%', 85, 95, 'system');
        $this->createSensor('Active Connections', '1.3.6.1.4.1.2604.5.1.3.1.0',   'gauge', 'connections', null, null, 'network');
        $this->log('Sophos system sensors discovered');
    }

    public function postDiscover(): void
    {
        $this->discoverVpns();

        // Collect ARP table for DHCP lease tracking
        try {
            CollectArpTableJob::dispatchSync($this->host);
        } catch (\Throwable $e) {
            $this->log('ARP collection failed: ' . $e->getMessage());
        }
    }

    protected function discoverVpns(): void
    {
        $vpnNames = $this->snmpWalk('1.3.6.1.4.1.2604.5.1.6.1.1.1.1.2');
        if (!$vpnNames) return;

        $count = 0;
        foreach ($vpnNames as $fullOid => $vpnNameRaw) {
            $vpnName = $this->cleanString($vpnNameRaw);
            if (preg_match('/\.(\d+)$/', $fullOid, $m)) {
                $index = $m[1];
                // Active (Administrative) status
                $this->createSensor("VPN: {$vpnName} - Active",
                    "1.3.6.1.4.1.2604.5.1.6.1.1.1.1.6.{$index}", 'boolean', null, null, null, 'VPN',
                    '2=Active/Enabled, 0=Disabled');
                // Connection (Operational) status
                $this->createSensor("VPN: {$vpnName} - Connection",
                    "1.3.6.1.4.1.2604.5.1.6.1.1.1.1.9.{$index}", 'boolean', null, null, null, 'VPN',
                    '1=Connected, 0=Disconnected');
                $count++;
            }
        }
        $this->log("Discovered {$count} VPN tunnels");
    }
}
