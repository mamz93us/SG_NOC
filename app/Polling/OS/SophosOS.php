<?php

namespace App\Polling\OS;

use App\Jobs\CollectArpTableJob;

class SophosOS extends BaseOS
{
    public function discoveredType(): string
    {
        return 'sophos';
    }

    public function hostType(): string
    {
        return 'firewall';
    }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Sophos') !== false
            || stripos($sysDescr, 'SFOS') !== false
            || str_contains($sysObjectID, '2604');
    }

    public function discoverSensors(): void
    {
        // SOPHOS-FIREWALL-MIB
        $this->createSensor('Memory Usage', '1.3.6.1.4.1.2604.5.1.2.4.2.0', 'gauge', '%', 80, 95, 'system');
        $this->createSensor('CPU Load', '1.3.6.1.4.1.2604.5.1.2.6.0', 'gauge', '%', 80, 95, 'system');
        $this->createSensor('Disk Usage', '1.3.6.1.4.1.2604.5.1.2.5.2.0', 'gauge', '%', 85, 95, 'system');
        $this->createSensor('Active Connections', '1.3.6.1.4.1.2604.5.1.3.1.0', 'gauge', 'connections', null, null, 'network');
        $this->log('Sophos system sensors discovered');
    }

    public function postDiscover(): void
    {
        $this->discoverVpns();

        // Collect ARP table for DHCP lease tracking
        try {
            CollectArpTableJob::dispatchSync($this->host);
        } catch (\Throwable $e) {
            $this->log('ARP collection failed: '.$e->getMessage());
        }
    }

    protected function discoverVpns(): void
    {
        $vpnNames = $this->snmpWalk('1.3.6.1.4.1.2604.5.1.6.1.1.1.1.2');

        // A failed walk is indistinguishable from "this firewall has no tunnels
        // left", so bail before the retire step rather than wiping the board on
        // a timeout.
        if (! $vpnNames) {
            return;
        }

        $count = 0;
        $seenOids = [];

        foreach ($vpnNames as $fullOid => $vpnNameRaw) {
            $vpnName = $this->cleanString($vpnNameRaw);
            if (preg_match('/\.(\d+)$/', $fullOid, $m)) {
                $index = $m[1];

                $activeOid = "1.3.6.1.4.1.2604.5.1.6.1.1.1.1.6.{$index}";
                $connectionOid = "1.3.6.1.4.1.2604.5.1.6.1.1.1.1.9.{$index}";

                // Active (Administrative) status — this is what separates a
                // tunnel someone switched off from one that has failed.
                $this->createSensor("VPN: {$vpnName} - Active",
                    $activeOid, 'boolean', null, null, null, 'VPN',
                    '2=Active/Enabled, 0=Disabled');
                // Connection (Operational) status
                $this->createSensor("VPN: {$vpnName} - Connection",
                    $connectionOid, 'boolean', null, null, null, 'VPN',
                    '1=Connected, 0=Disconnected');

                $seenOids[] = $activeOid;
                $seenOids[] = $connectionOid;
                $count++;
            }
        }

        // Tunnels deleted on the firewall stop appearing in the walk. Without
        // this they stayed on the NOC dashboard indefinitely, because nothing
        // ever removed the sensor rows discovery had created.
        $retired = $this->retireUnseenSensors('VPN', $seenOids);

        $this->log("Discovered {$count} VPN tunnels"
            .($retired > 0 ? ", retired {$retired} sensor(s) no longer on the firewall" : ''));
    }
}
