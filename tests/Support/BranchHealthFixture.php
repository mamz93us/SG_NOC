<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Seeds branch telemetry for the health-score tests.
 *
 * `healthy()` writes the minimum inventory that makes all twelve checks pass, so
 * a test can start from a known 100 and degrade exactly one thing. Every method
 * takes explicit timestamps where freshness is the point of the test.
 *
 * Rows go in through the query builder rather than models: several of these
 * tables have guarded telemetry columns (VoiceMeshPair mass-assigns only its two
 * node ids, VoiceMeshNode excludes its rolled-up state) precisely so production
 * code cannot write them casually, and a fixture should not have to fight that.
 */
class BranchHealthFixture
{
    /**
     * A branch where every check passes — the 100-point baseline.
     *
     * Note this seeds TWO voice mesh nodes: a branch's mesh denominator is every
     * other active node, so a single-node mesh has nothing to call and scores
     * `unknown` rather than full marks.
     */
    public static function healthy(int $branchId, string $name = 'Test Branch'): void
    {
        BranchHealthSchema::seedBranches([[$branchId, $name]]);

        self::ucm($branchId, ok: true, trunks: [['T1', 'reachable'], ['T2', 'registered']]);
        self::mesh($branchId, peers: [[99, 'PEER']], allOk: true);
        self::mos($branchId, passing: 10, failing: 0);
        self::firewall($branchId, state: 'up');
        self::isp($branchId, success: true);
        self::switches($branchId, up: 1, down: 0);
        self::accessPoints($branchId, up: 1, down: 0);
        self::printers($branchId, up: 1, down: 0, tonerPercent: 80);
        self::biometrics($branchId, up: 1, down: 0);
    }

    // ── VoIP ─────────────────────────────────────────────────────────

    /** @param  array<int, array{0:string,1:string}>  $trunks  [name, status] */
    public static function ucm(int $branchId, bool $ok = true, array $trunks = [], ?\DateTimeInterface $at = null): int
    {
        $at ??= now();

        $ucmId = DB::table('ucm_servers')->insertGetId([
            'name' => 'UCM '.$branchId,
            'url' => 'https://ucm'.$branchId.'.local',
            'is_active' => true,
            'last_health_ok' => $ok,
            'last_health_at' => $at,
            'last_health_error' => $ok ? null : 'Connection refused',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('branches')->where('id', $branchId)->update(['ucm_server_id' => $ucmId]);

        foreach ($trunks as $i => [$trunkName, $status]) {
            DB::table('ucm_trunks_cache')->insert([
                'ucm_id' => $ucmId,
                'trunk_name' => $trunkName,
                'trunk_index' => (string) ($i + 1),
                'status' => $status,
                'last_checked_at' => $at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ucmId;
    }

    /**
     * @param  array<int, array{0:int,1:string}>  $peers  [branchId, code] for the other nodes
     * @param  array<string, bool>  $legOk  peer code => did that leg pass
     */
    public static function mesh(
        int $branchId,
        array $peers = [],
        bool $allOk = true,
        array $legOk = [],
        ?\DateTimeInterface $checkedAt = null,
    ): void {
        $checkedAt ??= now();

        $selfId = DB::table('voice_mesh_nodes')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'B'.$branchId,
            'name' => 'Node '.$branchId,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($peers as [$peerBranchId, $code]) {
            $peerId = DB::table('voice_mesh_nodes')->insertGetId([
                'branch_id' => $peerBranchId,
                'code' => $code,
                'name' => $code.' Node',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $ok = $legOk[$code] ?? $allOk;

            DB::table('voice_mesh_pairs')->insert([
                'caller_node_id' => $selfId,
                'dest_node_id' => $peerId,
                'status' => $ok ? 'ok' : 'fail',
                // RTP actually arriving is what separates "call set up" from
                // "call carried audio", so a passing leg must have packets.
                'last_rx_pkt' => $ok ? 250 : 0,
                'last_reason' => $ok ? 'OK' : 'No answer',
                'last_checked_at' => $checkedAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function mos(int $branchId, int $passing, int $failing, ?\DateTimeInterface $at = null): void
    {
        $at ??= now()->subMinutes(5);

        for ($i = 0; $i < $passing; $i++) {
            DB::table('voice_quality_reports')->insert([
                'extension' => (string) ($branchId * 1000 + $i),
                'branch_id' => $branchId,
                'mos_lq' => 4.4,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        for ($i = 0; $i < $failing; $i++) {
            DB::table('voice_quality_reports')->insert([
                'extension' => (string) ($branchId * 1000 + 500 + $i),
                'branch_id' => $branchId,
                'mos_lq' => 3.2,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    // ── Network ──────────────────────────────────────────────────────

    public static function firewall(
        int $branchId,
        string $state = 'up',
        ?\DateTimeInterface $pingedAt = null,
        string $ip = '10.0.0.1',
    ): int {
        $pingedAt ??= now();

        DB::table('sophos_firewalls')->insert([
            'branch_id' => $branchId,
            'name' => 'FW '.$branchId,
            'ip' => $ip,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('branch_tunnels')->insertGetId([
            'branch_id' => $branchId,
            'name' => 'Tunnel '.$branchId,
            'firewall_ip' => $ip,
            'is_active' => true,
            'state' => $state,
            'last_ping_at' => $pingedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function isp(int $branchId, bool $success = true, ?\DateTimeInterface $checkedAt = null): void
    {
        $checkedAt ??= now();

        $ispId = DB::table('isp_connections')->insertGetId([
            'branch_id' => $branchId,
            'provider' => 'TestISP',
            'gateway' => '10.'.$branchId.'.0.254',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('link_checks')->insert([
            'isp_id' => $ispId,
            'success' => $success,
            'latency' => $success ? 12.0 : null,
            'checked_at' => $checkedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Switches monitored by ping (MonitoredHost), the freshest source. */
    public static function switches(int $branchId, int $up, int $down, ?\DateTimeInterface $pingedAt = null): void
    {
        $pingedAt ??= now();

        foreach ([['up', $up], ['down', $down]] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $deviceId = DB::table('devices')->insertGetId([
                    'branch_id' => $branchId,
                    'name' => "SW-{$branchId}-{$status}-{$i}",
                    'type' => 'switch',
                    'ip_address' => "10.{$branchId}.1.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                DB::table('monitored_hosts')->insert([
                    'device_id' => $deviceId,
                    'branch_id' => $branchId,
                    'name' => "SW-{$branchId}-{$status}-{$i}",
                    'ip' => "10.{$branchId}.1.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'type' => 'switch',
                    'status' => $status,
                    'last_ping_at' => $pingedAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** A Meraki-backed switch, for testing which source wins on freshness. */
    public static function merakiSwitch(
        int $branchId,
        string $merakiStatus,
        ?\DateTimeInterface $merakiAt,
        ?string $hostStatus = null,
        ?\DateTimeInterface $hostAt = null,
    ): void {
        $deviceId = DB::table('devices')->insertGetId([
            'branch_id' => $branchId,
            'name' => 'SW-meraki-'.$branchId,
            'type' => 'switch',
            'ip_address' => '10.'.$branchId.'.9.9',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('network_switches')->insert([
            'device_id' => $deviceId,
            'branch_id' => $branchId,
            'name' => 'SW-meraki-'.$branchId,
            'status' => $merakiStatus,
            'last_reported_at' => $merakiAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($hostStatus !== null) {
            DB::table('monitored_hosts')->insert([
                'device_id' => $deviceId,
                'branch_id' => $branchId,
                'name' => 'SW-meraki-'.$branchId,
                'ip' => '10.'.$branchId.'.9.9',
                'status' => $hostStatus,
                'last_ping_at' => $hostAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function accessPoints(int $branchId, int $up, int $down, ?\DateTimeInterface $pingedAt = null): void
    {
        $pingedAt ??= now();

        foreach ([['up', $up], ['down', $down]] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                DB::table('access_points')->insert([
                    'branch_id' => $branchId,
                    'name' => "AP-{$branchId}-{$status}-{$i}",
                    'ip_address' => "10.{$branchId}.2.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'monitor_enabled' => true,
                    'status' => $status,
                    'last_ping_at' => $pingedAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    // ── Devices ──────────────────────────────────────────────────────

    public static function printers(
        int $branchId,
        int $up,
        int $down,
        ?int $tonerPercent = 80,
        ?\DateTimeInterface $pingedAt = null,
        ?\DateTimeInterface $tonerAt = null,
    ): void {
        $pingedAt ??= now();
        $tonerAt ??= now();

        foreach ([['up', $up], ['down', $down]] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $deviceId = DB::table('devices')->insertGetId([
                    'branch_id' => $branchId,
                    'name' => "PRN-{$branchId}-{$status}-{$i}",
                    'type' => 'printer',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $printerId = DB::table('printers')->insertGetId([
                    'device_id' => $deviceId,
                    'branch_id' => $branchId,
                    'printer_name' => "PRN-{$branchId}-{$status}-{$i}",
                    'ip_address' => "10.{$branchId}.3.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'snmp_last_polled_at' => $tonerAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                DB::table('monitored_hosts')->insert([
                    'device_id' => $deviceId,
                    'branch_id' => $branchId,
                    'name' => "PRN-{$branchId}-{$status}-{$i}",
                    'ip' => "10.{$branchId}.3.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'status' => $status,
                    'last_ping_at' => $pingedAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                if ($tonerPercent !== null) {
                    DB::table('printer_supplies')->insert([
                        'printer_id' => $printerId,
                        'supply_index' => 1,
                        'supply_type' => 'toner',
                        'supply_color' => 'black',
                        'supply_percent' => $tonerPercent,
                        'last_updated_at' => $tonerAt,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /** @param  array<int, int>  $percents  one supply row per entry */
    public static function toner(int $printerId, array $percents, ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        $colors = ['black', 'cyan', 'magenta', 'yellow'];

        foreach ($percents as $i => $percent) {
            DB::table('printer_supplies')->insert([
                'printer_id' => $printerId,
                'supply_index' => 10 + $i,
                'supply_type' => 'toner',
                'supply_color' => $colors[$i % 4],
                'supply_percent' => $percent,
                'last_updated_at' => $at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function biometrics(
        int $branchId,
        int $up,
        int $down,
        bool $withHost = true,
        ?\DateTimeInterface $pingedAt = null,
    ): void {
        $pingedAt ??= now();

        foreach ([['up', $up], ['down', $down]] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $deviceId = DB::table('devices')->insertGetId([
                    'branch_id' => $branchId,
                    'name' => "BIO-{$branchId}-{$status}-{$i}",
                    'type' => 'biometric',
                    'ip_address' => "10.{$branchId}.4.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                if (! $withHost) {
                    continue;
                }

                DB::table('monitored_hosts')->insert([
                    'device_id' => $deviceId,
                    'branch_id' => $branchId,
                    'name' => "BIO-{$branchId}-{$status}-{$i}",
                    'ip' => "10.{$branchId}.4.".(10 + $i + ($status === 'down' ? 100 : 0)),
                    'status' => $status,
                    'last_ping_at' => $pingedAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public static function criticalAlert(int $branchId, string $sourceType = 'tunnel_down', string $status = 'open'): void
    {
        DB::table('noc_events')->insert([
            'module' => 'vpn',
            'branch_id' => $branchId,
            'source_type' => $sourceType,
            'source_id' => '1',
            'severity' => 'critical',
            'status' => $status,
            'title' => 'Tunnel Down: Branch '.$branchId,
            'message' => 'Tunnel unreachable',
            'first_seen' => now(), 'last_seen' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
