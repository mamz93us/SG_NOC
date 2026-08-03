<?php

namespace App\Polling\OS;

/**
 * FortiGateOS — Fortinet FortiGate / FortiWiFi firewalls (FortiOS).
 *
 * All OIDs below were resolved from the vendor MIBs shipped in database/mibs/:
 *   FORTINET-CORE-MIB      (fortinet          = .1.3.6.1.4.1.12356)
 *   FORTINET-FORTIGATE-MIB (fnFortiGateMib    = .1.3.6.1.4.1.12356.101)
 *
 * Static system counters are created up-front; hardware sensors, IPsec tunnels,
 * SD-WAN link monitors and HA members are walked at discovery time so each
 * firewall only gets the sensors it actually reports.
 */
class FortiGateOS extends BaseOS
{
    /** fnFortiGateMib */
    private const BASE = '1.3.6.1.4.1.12356.101';

    /** fgHwSensorEntry columns (INDEX: fgHwSensorEntIndex) */
    private const HW_SENSOR_NAME = self::BASE.'.4.3.2.1.2';

    private const HW_SENSOR_VALUE = self::BASE.'.4.3.2.1.3';

    private const HW_SENSOR_ALARM = self::BASE.'.4.3.2.1.4';

    /** fgLinkMonitorEntry columns (INDEX: fgLinkMonitorID) */
    private const LINKMON_NAME = self::BASE.'.4.8.2.1.2';

    private const LINKMON_STATE = self::BASE.'.4.8.2.1.3';

    private const LINKMON_LAT = self::BASE.'.4.8.2.1.4';

    private const LINKMON_JITTER = self::BASE.'.4.8.2.1.5';

    private const LINKMON_LOSS = self::BASE.'.4.8.2.1.8';

    /** fgVpnTunEntry columns (INDEX: fgVpnTunEntIndex, fgVpnTunEntPhase2Index — 2 parts) */
    private const VPN_P1_NAME = self::BASE.'.12.2.2.1.2';

    private const VPN_P2_NAME = self::BASE.'.12.2.2.1.3';

    private const VPN_STATUS = self::BASE.'.12.2.2.1.20';

    /** fgVpnSslStatsEntry (AUGMENTS fgVdEntry — indexed by vdom) */
    private const SSLVPN_USERS = self::BASE.'.12.2.3.1.2';

    /** fgHaStatsEntry columns (INDEX: fgHaStatsIndex) */
    private const HA_HOSTNAME = self::BASE.'.13.2.1.1.11';

    private const HA_CPU = self::BASE.'.13.2.1.1.3';

    private const HA_MEM = self::BASE.'.13.2.1.1.4';

    private const HA_SESSIONS = self::BASE.'.13.2.1.1.6';

    /** Per-table caps so a large chassis cannot flood the poller. */
    private const MAX_HW_SENSORS = 40;

    private const MAX_VPN_TUNNELS = 60;

    private const MAX_LINKMONS = 30;

    private const MAX_HA_MEMBERS = 8;

    public function discoveredType(): string
    {
        return 'fortigate';
    }

    public function hostType(): string
    {
        return 'firewall';
    }

    public static function detect(string $sysDescr, string $sysObjectID): bool
    {
        return stripos($sysDescr, 'Fortinet') !== false
            || stripos($sysDescr, 'FortiGate') !== false
            || stripos($sysDescr, 'FortiWiFi') !== false
            || stripos($sysDescr, 'FortiOS') !== false
            || str_contains($sysObjectID, '1.3.6.1.4.1.12356.101.');
    }

    public function discoverSensors(): void
    {
        // ── fgSystemInfo scalars ────────────────────────────────────────────
        $this->createSensor('CPU Usage', self::BASE.'.4.1.3.0', 'gauge', '%', 80, 95, 'system',
            'fgSysCpuUsage — current CPU usage (percentage)');

        $this->createSensor('Memory Usage', self::BASE.'.4.1.4.0', 'gauge', '%', 80, 95, 'system',
            'fgSysMemUsage — current memory utilization (percentage)');

        // Conserve mode is driven by lowmem pressure, so it is worth its own graph.
        $this->createSensor('Low Memory Usage', self::BASE.'.4.1.9.0', 'gauge', '%', 85, 95, 'system',
            'fgSysLowMemUsage — kernel lowmem utilization; sustained highs precede conserve mode');

        // NOTE: fgSysDiskUsage is reported in MB, not percent — no percentage thresholds.
        $this->createSensor('Disk Usage', self::BASE.'.4.1.6.0', 'gauge', 'MB', null, null, 'system',
            'fgSysDiskUsage — hard disk usage in MB (capacity: fgSysDiskCapacity '.self::BASE.'.4.1.7.0)');

        $this->createSensor('Active Sessions', self::BASE.'.4.1.8.0', 'gauge', 'sessions', null, null, 'network',
            'fgSysSesCount — active sessions on the device');

        $this->createSensor('Session Setup Rate (1m)', self::BASE.'.4.1.11.0', 'gauge', 'sessions/s', null, null, 'network',
            'fgSysSesRate1 — average session setup rate over the past minute');

        $this->createSensor('Authenticated Users', self::BASE.'.5.2.1.1.0', 'gauge', 'users', null, null, 'network',
            'fgFwUserNumber — firewall user accounts currently in fgFwUserTable');

        $this->log('FortiGate system sensors discovered');
    }

    public function postDiscover(): void
    {
        $this->discoverHardwareSensors();
        $this->discoverVpnTunnels();
        $this->discoverLinkMonitors();
        $this->discoverSslVpn();
        $this->discoverHaMembers();
    }

    // ─── fgHwSensorTable — temperature / fan / voltage / PSU ─────────────────

    protected function discoverHardwareSensors(): void
    {
        $names = $this->snmpWalk(self::HW_SENSOR_NAME);
        if (! $names) {
            $this->log('No hardware sensors reported (normal on desktop models)');

            return;
        }

        $created = 0;
        $skipped = 0;
        foreach ($names as $fullOid => $raw) {
            $index = $this->tailIndex($fullOid, 1);
            $name = $this->cleanString($raw);
            if ($index === null || $name === null || $name === '') {
                continue;
            }
            if ($created >= self::MAX_HW_SENSORS) {
                $skipped++;

                continue;
            }

            [$unit, $warn, $crit] = $this->hardwareSensorUnit($name);

            $this->createSensor("HW: {$name}", self::HW_SENSOR_VALUE.".{$index}", 'gauge', $unit, $warn, $crit,
                'environment', 'fgHwSensorEntValue — reading reported as a DisplayString');

            // fgHwSensorEntAlarmStatus is INTEGER { false(0), true(1) } and is
            // normalised to 1 = healthy by CollectSnmpMetricsJob.
            $this->createSensor("HW: {$name} - Alarm", self::HW_SENSOR_ALARM.".{$index}", 'boolean', null, null, null,
                'environment', 'fgHwSensorEntAlarmStatus — 0=no alarm, 1=threshold exceeded (graphed as 1=healthy)');

            $created++;
        }

        $this->log("Discovered {$created} hardware sensors"
            .($skipped > 0 ? " ({$skipped} skipped — cap of ".self::MAX_HW_SENSORS.' reached)' : ''));
    }

    /**
     * Guess unit and thresholds from the sensor name. FortiOS names are free-form
     * (e.g. "DTS CPU0", "FAN1", "+12V", "PS1 Status").
     *
     * @return array{0: ?string, 1: ?float, 2: ?float}
     */
    protected function hardwareSensorUnit(string $name): array
    {
        $n = strtolower($name);

        if (str_contains($n, 'temp') || str_contains($n, 'dts') || str_contains($n, 'therm')) {
            return ['°C', 75, 85];
        }
        if (str_contains($n, 'fan')) {
            // Low RPM is the failure mode, and the threshold engine is high-is-bad — no thresholds.
            return ['RPM', null, null];
        }
        if (str_contains($n, 'volt') || str_contains($n, 'vcc') || str_contains($n, 'vin') || preg_match('/\d+v\b/', $n)) {
            return ['V', null, null];
        }

        return [null, null, null];
    }

    // ─── fgVpnTunTable — IPsec phase 2 tunnels ──────────────────────────────

    protected function discoverVpnTunnels(): void
    {
        $p2Names = $this->snmpWalk(self::VPN_P2_NAME);
        if (! $p2Names) {
            $this->log('No IPsec tunnels reported');

            return;
        }

        // Phase 1 names give the tunnel useful context (gateway name vs selector name).
        $p1Names = $this->snmpWalk(self::VPN_P1_NAME) ?: [];
        $p1ByIndex = [];
        foreach ($p1Names as $oid => $raw) {
            if (($idx = $this->tailIndex($oid, 2)) !== null) {
                $p1ByIndex[$idx] = $this->cleanString($raw);
            }
        }

        $created = 0;
        $skipped = 0;
        foreach ($p2Names as $fullOid => $raw) {
            // fgVpnTunEntry is indexed by { fgVpnTunEntIndex, fgVpnTunEntPhase2Index } — two components.
            $index = $this->tailIndex($fullOid, 2);
            $p2 = $this->cleanString($raw);
            if ($index === null || $p2 === null || $p2 === '') {
                continue;
            }
            if ($created >= self::MAX_VPN_TUNNELS) {
                $skipped++;

                continue;
            }

            $p1 = $p1ByIndex[$index] ?? null;
            $label = ($p1 && $p1 !== $p2) ? "{$p1} / {$p2}" : $p2;

            // fgVpnTunEntStatus is INTEGER { down(1), up(2) } — inverted vs. the
            // generic boolean rule, so CollectSnmpMetricsJob special-cases this OID.
            $this->createSensor("VPN: {$label}", self::VPN_STATUS.".{$index}", 'boolean', null, null, null,
                'VPN', 'fgVpnTunEntStatus — 2=up, 1=down (graphed as 1=up)');

            $created++;
        }

        $this->log("Discovered {$created} IPsec tunnels"
            .($skipped > 0 ? " ({$skipped} skipped — cap of ".self::MAX_VPN_TUNNELS.' reached)' : ''));
    }

    // ─── fgLinkMonitorTable — SD-WAN / WAN health checks ────────────────────

    protected function discoverLinkMonitors(): void
    {
        $names = $this->snmpWalk(self::LINKMON_NAME);
        if (! $names) {
            $this->log('No link monitors configured');

            return;
        }

        $created = 0;
        $skipped = 0;
        foreach ($names as $fullOid => $raw) {
            $index = $this->tailIndex($fullOid, 1);
            $name = $this->cleanString($raw);
            if ($index === null || $name === null || $name === '') {
                continue;
            }
            if ($created >= self::MAX_LINKMONS) {
                $skipped++;

                continue;
            }

            // fgLinkMonitorState is INTEGER { alive(0), dead(1) } — inverted, see CollectSnmpMetricsJob.
            $this->createSensor("Link: {$name}", self::LINKMON_STATE.".{$index}", 'boolean', null, null, null,
                'link_monitor', 'fgLinkMonitorState — 0=alive, 1=dead (graphed as 1=alive)');

            $this->createSensor("Link: {$name} - Latency", self::LINKMON_LAT.".{$index}", 'gauge', 'ms', 150, 300,
                'link_monitor', 'fgLinkMonitorLatency — average latency over the last 30 probes');

            $this->createSensor("Link: {$name} - Jitter", self::LINKMON_JITTER.".{$index}", 'gauge', 'ms', 30, 60,
                'link_monitor', 'fgLinkMonitorJitter — average jitter over the last 30 probes');

            $this->createSensor("Link: {$name} - Packet Loss", self::LINKMON_LOSS.".{$index}", 'gauge', '%', 2, 10,
                'link_monitor', 'fgLinkMonitorPacketLoss — average packet loss over the last 30 probes');

            $created++;
        }

        $this->log("Discovered {$created} link monitors"
            .($skipped > 0 ? " ({$skipped} skipped — cap of ".self::MAX_LINKMONS.' reached)' : ''));
    }

    // ─── fgVpnSslStatsTable — SSL-VPN logged-in users, per VDOM ──────────────

    protected function discoverSslVpn(): void
    {
        $users = $this->snmpWalk(self::SSLVPN_USERS);
        if (! $users) {
            return;
        }

        $vdomNames = $this->snmpWalk(self::BASE.'.3.2.1.1.2') ?: []; // fgVdEntName
        $byIndex = [];
        foreach ($vdomNames as $oid => $raw) {
            if (($idx = $this->tailIndex($oid, 1)) !== null) {
                $byIndex[$idx] = $this->cleanString($raw);
            }
        }

        $created = 0;
        foreach ($users as $fullOid => $_) {
            $index = $this->tailIndex($fullOid, 1);
            if ($index === null) {
                continue;
            }
            $vdom = $byIndex[$index] ?? "vdom{$index}";
            $label = ($vdom === 'root') ? 'SSL-VPN Users' : "SSL-VPN Users ({$vdom})";

            $this->createSensor($label, self::SSLVPN_USERS.".{$index}", 'gauge', 'users', null, null, 'VPN',
                'fgVpnSslStatsLoginUsers — users currently logged in through SSL-VPN');
            $created++;
        }

        $this->log("Discovered {$created} SSL-VPN counters");
    }

    // ─── fgHaStatsTable — cluster member load ───────────────────────────────

    protected function discoverHaMembers(): void
    {
        $mode = $this->cleanString($this->snmpGet(self::BASE.'.13.1.1.0') ?: null); // fgHaSystemMode
        $hostnames = $this->snmpWalk(self::HA_HOSTNAME);

        if (! $hostnames) {
            $this->log('Standalone (no HA cluster members reported)'.($mode ? " — fgHaSystemMode={$mode}" : ''));

            return;
        }

        $created = 0;
        $skipped = 0;
        foreach ($hostnames as $fullOid => $raw) {
            $index = $this->tailIndex($fullOid, 1);
            $name = $this->cleanString($raw);
            if ($index === null || $name === null || $name === '') {
                continue;
            }
            if ($created >= self::MAX_HA_MEMBERS) {
                $skipped++;

                continue;
            }

            $this->createSensor("HA: {$name} - CPU", self::HA_CPU.".{$index}", 'gauge', '%', 80, 95, 'ha',
                'fgHaStatsCpuUsage — CPU usage of this cluster member');

            $this->createSensor("HA: {$name} - Memory", self::HA_MEM.".{$index}", 'gauge', '%', 80, 95, 'ha',
                'fgHaStatsMemUsage — memory usage of this cluster member');

            $this->createSensor("HA: {$name} - Sessions", self::HA_SESSIONS.".{$index}", 'gauge', 'sessions', null, null, 'ha',
                'fgHaStatsSesCount — active sessions on this cluster member');

            $created++;
        }

        $this->log("Discovered {$created} HA cluster members"
            .($skipped > 0 ? " ({$skipped} skipped — cap of ".self::MAX_HA_MEMBERS.' reached)' : ''));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Pull the last $parts index components off a walked OID.
     *
     * Walk keys may come back numeric (".1.3.6...12356.101.12.2.2.1.3.1.1") or
     * symbolic ("FORTINET-FORTIGATE-MIB::fgVpnTunEntPhase2Name.1.1") depending on
     * whether the PHP ext or the CLI fallback is in use, so we anchor on the tail
     * rather than stripping a known prefix. fgVpnTunEntry needs 2 parts, every
     * other table used here needs 1.
     */
    protected function tailIndex(string $fullOid, int $parts): ?string
    {
        if (! preg_match('/((?:\d+\.)*\d+)$/', trim($fullOid), $m)) {
            return null;
        }

        $segments = explode('.', $m[1]);
        if (count($segments) < $parts) {
            return null;
        }

        return implode('.', array_slice($segments, -$parts));
    }
}
