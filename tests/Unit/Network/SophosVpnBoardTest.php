<?php

use App\Models\SnmpSensor;
use App\Services\Sophos\SophosVpnBoard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Sophos site-to-site VPN board.
 *
 * Two faults it exists to fix:
 *
 *  1. Tunnels deleted from the firewall stayed on the NOC dashboard forever --
 *     discovery created sensor rows and nothing ever removed them.
 *  2. A tunnel switched off deliberately (a backup link held in reserve) was
 *     rendered as a red "down", identical to one that had failed.
 *
 * Binds Tests\TestCase without RefreshDatabase for the usual reason: MySQL-only
 * migrations in this repo cannot run on the SQLite test connection.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['sensor_metrics', 'snmp_sensors', 'monitored_hosts', 'branches'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
    });
    Schema::create('monitored_hosts', function (Blueprint $t) {
        $t->id();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('name')->nullable();
        $t->string('ip')->nullable();
    });
    Schema::create('snmp_sensors', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('host_id');
        $t->string('name');
        $t->string('oid');
        $t->string('description')->nullable();
        $t->string('data_type')->nullable();
        $t->string('sensor_group')->nullable();
        $t->string('status')->nullable();
        $t->boolean('graph_enabled')->default(true);
        $t->boolean('monitor_enabled')->default(true);
        $t->timestamp('last_recorded_at')->nullable();
        $t->timestamp('retired_at')->nullable();
        $t->timestamps();
    });
    Schema::create('sensor_metrics', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('sensor_id');
        $t->float('value')->nullable();
        $t->timestamp('recorded_at')->nullable();
    });

    DB::table('branches')->insert(['id' => 1, 'name' => 'Jeddah']);
    DB::table('monitored_hosts')->insert(['id' => 1, 'branch_id' => 1, 'name' => 'FW-JED', 'ip' => '10.1.0.1']);
});

/**
 * One tunnel: its Active sensor and its Connection sensor, each with a reading.
 *
 * @param  float|null  $active  2 = enabled, 0 = administratively disabled
 * @param  float|null  $connection  1 = connected, 0 = disconnected
 */
function seedTunnel(
    string $name,
    ?float $active = 2.0,
    ?float $connection = 1.0,
    bool $monitored = true,
    bool $retired = false,
    int $hostId = 1,
): int {
    $connectionId = 0;

    foreach ([['Active', $active], ['Connection', $connection]] as $i => [$suffix, $value]) {
        $id = DB::table('snmp_sensors')->insertGetId([
            'host_id' => $hostId,
            'name' => "VPN: {$name} - {$suffix}",
            'oid' => "1.3.6.1.4.1.2604.5.1.6.1.1.1.1.{$i}.".crc32($name.$hostId),
            'sensor_group' => 'VPN',
            'monitor_enabled' => $monitored,
            'retired_at' => $retired ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($value !== null) {
            DB::table('sensor_metrics')->insert([
                'sensor_id' => $id, 'value' => $value, 'recorded_at' => now(),
            ]);
        }

        if ($suffix === 'Connection') {
            $connectionId = $id;
        }
    }

    return $connectionId;
}

function board(): SophosVpnBoard
{
    return app(SophosVpnBoard::class);
}

// ─────────────────────────────────────────────────────────────────────

it('pairs the Active and Connection sensors into one row per tunnel', function () {
    seedTunnel('Branch-JED');
    seedTunnel('Branch-RYD');

    $tunnels = board()->tunnels();

    // Four sensors, two tunnels.
    expect($tunnels)->toHaveCount(2)
        ->and($tunnels->pluck('name')->sort()->values()->all())->toBe(['Branch-JED', 'Branch-RYD']);
});

it('reports a connected tunnel as up', function () {
    seedTunnel('Live', active: 2.0, connection: 1.0);

    expect(board()->tunnels()->first()['status'])->toBe(SophosVpnBoard::STATUS_UP);
});

it('reports a failed tunnel as down', function () {
    seedTunnel('Broken', active: 2.0, connection: 0.0);

    expect(board()->tunnels()->first()['status'])->toBe(SophosVpnBoard::STATUS_DOWN);
});

it('distinguishes a tunnel disabled on the firewall from one that failed', function () {
    // The backup-link case: administratively off, so naturally not connected.
    // Reading Connection alone would call this "down" and page someone.
    seedTunnel('Backup-Link', active: 0.0, connection: 0.0);

    $row = board()->tunnels()->first();

    expect($row['status'])->toBe(SophosVpnBoard::STATUS_DISABLED)
        ->and($row['status'])->not->toBe(SophosVpnBoard::STATUS_DOWN);
});

it('keeps a disabled tunnel out of the up and down counts', function () {
    seedTunnel('Live', active: 2.0, connection: 1.0);
    seedTunnel('Backup', active: 0.0, connection: 0.0);
    seedTunnel('Broken', active: 2.0, connection: 0.0);

    $summary = board()->summary(board()->tunnels());

    expect($summary['up'])->toBe(1)
        ->and($summary['down'])->toBe(1)
        ->and($summary['disabled'])->toBe(1);
});

it('honours an operator mute even when the tunnel is enabled and down', function () {
    seedTunnel('Partner-Circuit', active: 2.0, connection: 0.0, monitored: false);

    $row = board()->tunnels()->first();

    // Muted wins: the operator has said they do not want to hear about it, and
    // a muted tunnel that is also down should not still be shouting.
    expect($row['status'])->toBe(SophosVpnBoard::STATUS_MUTED)
        ->and($row['monitor_enabled'])->toBeFalse();
});

it('reports a tunnel with no reading as unknown, never as up', function () {
    seedTunnel('Never-Polled', active: 2.0, connection: null);

    expect(board()->tunnels()->first()['status'])->toBe(SophosVpnBoard::STATUS_UNKNOWN);
});

it('hides retired tunnels by default and can show them on request', function () {
    seedTunnel('Live');
    seedTunnel('Deleted-From-Firewall', retired: true);

    expect(board()->tunnels()->pluck('name')->all())->toBe(['Live']);

    $all = board()->tunnels(includeRetired: true);
    expect($all)->toHaveCount(2)
        ->and($all->firstWhere('name', 'Deleted-From-Firewall')['retired'])->toBeTrue();

    expect(board()->retiredCount())->toBe(1);
});

it('sorts worst first so what needs attention is on top', function () {
    seedTunnel('AAA-Muted', monitored: false);
    seedTunnel('BBB-Up', active: 2.0, connection: 1.0);
    seedTunnel('CCC-Down', active: 2.0, connection: 0.0);
    seedTunnel('DDD-Disabled', active: 0.0, connection: 0.0);

    expect(board()->tunnels()->pluck('name')->all())
        ->toBe(['CCC-Down', 'BBB-Up', 'DDD-Disabled', 'AAA-Muted']);
});

it('does not confuse same-named tunnels on different firewalls', function () {
    DB::table('monitored_hosts')->insert(['id' => 2, 'branch_id' => 1, 'name' => 'FW-RYD', 'ip' => '10.2.0.1']);

    seedTunnel('Backup', active: 2.0, connection: 1.0, hostId: 1);
    seedTunnel('Backup', active: 2.0, connection: 0.0, hostId: 2);

    $rows = board()->tunnels();

    // Two firewalls can both have a tunnel called "Backup"; keying by name alone
    // would merge them and hide one of the two states.
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->sort()->values()->all())
        ->toBe([SophosVpnBoard::STATUS_DOWN, SophosVpnBoard::STATUS_UP]);
});

it('ignores an Active sensor with no Connection half', function () {
    DB::table('snmp_sensors')->insert([
        'host_id' => 1, 'name' => 'VPN: Orphan - Active', 'oid' => '1.2.3',
        'sensor_group' => 'VPN', 'monitor_enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Connection carries the operational state; a stray Active on its own is not
    // a tunnel the board can say anything useful about.
    expect(board()->tunnels())->toBeEmpty();
});

it('parses the tunnel name off either sensor', function () {
    seedTunnel('Site-To-Site 01');

    $sensor = SnmpSensor::where('name', 'like', '%Connection')->first();
    $active = SnmpSensor::where('name', 'like', '%Active')->first();

    expect($sensor->vpnTunnelName())->toBe('Site-To-Site 01')
        ->and($active->vpnTunnelName())->toBe('Site-To-Site 01')
        ->and($sensor->isVpnConnectionSensor())->toBeTrue()
        ->and($active->isVpnActiveSensor())->toBeTrue();
});
