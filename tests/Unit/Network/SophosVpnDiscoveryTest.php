<?php

use App\Models\MonitoredHost;
use App\Models\SnmpSensor;
use App\Polling\OS\SophosOS;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sophos VPN sensor discovery, and what happens to tunnels that disappear.
 *
 * The retire step is the dangerous half of this feature: an SNMP walk that
 * fails, or comes back short, looks exactly like a firewall whose tunnels were
 * all deleted. Getting that wrong would blank the NOC dashboard on a timeout,
 * so most of these tests are about what must NOT happen.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['sensor_metrics', 'snmp_sensors', 'monitored_hosts'] as $t) {
        Schema::dropIfExists($t);
    }

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
        $t->string('unit')->nullable();
        $t->unsignedInteger('poll_interval')->nullable();
        $t->float('warning_threshold')->nullable();
        $t->float('critical_threshold')->nullable();
        $t->string('sensor_group')->nullable();
        $t->string('status')->nullable();
        $t->boolean('graph_enabled')->default(true);
        $t->boolean('monitor_enabled')->default(true);
        $t->timestamp('last_recorded_at')->nullable();
        $t->timestamp('retired_at')->nullable();
        $t->timestamps();
    });

    DB::table('monitored_hosts')->insert(['id' => 1, 'name' => 'FW-JED', 'ip' => '10.1.0.1']);
});

/**
 * SophosOS with the SNMP transport replaced by a fixture.
 *
 * @param  array<string, string>|false  $walk  what the VPN-name walk returns
 */
function sophosOs(array|false $walk): SophosOS
{
    $host = MonitoredHost::find(1);

    return new class($host, $walk) extends SophosOS
    {
        public function __construct(MonitoredHost $host, private array|false $walkFixture)
        {
            // The client is required by the signature but never reached:
            // snmpWalk() below is what discovery actually calls, and it is
            // overridden to return the fixture.
            parent::__construct($host, Mockery::mock(\App\Services\Snmp\SnmpClient::class));
        }

        protected function snmpWalk(string $oid): array|false
        {
            return $this->walkFixture;
        }

        /** Exposed so the test can call the discovery step directly. */
        public function runVpnDiscovery(): void
        {
            $this->discoverVpns();
        }
    };
}

/**
 * A VPN-name walk.
 *
 * The index matters: sensors are identified by OID, and the OID carries the
 * firewall's table index. Two different tunnels therefore need two different
 * indices, exactly as they would have on the device.
 *
 * @param  array<int, string>  $names  tunnel names the firewall reports
 */
function walkOf(array $names, int $startIndex = 1): array
{
    $walk = [];
    foreach (array_values($names) as $i => $name) {
        $walk['1.3.6.1.4.1.2604.5.1.6.1.1.1.1.2.'.($startIndex + $i)] = $name;
    }

    return $walk;
}

// ─────────────────────────────────────────────────────────────────────

it('creates an Active and a Connection sensor for every tunnel', function () {
    sophosOs(walkOf(['JED-Main', 'RYD-Backup']))->runVpnDiscovery();

    expect(SnmpSensor::where('sensor_group', 'VPN')->count())->toBe(4);
    expect(SnmpSensor::pluck('name')->sort()->values()->all())->toBe([
        'VPN: JED-Main - Active',
        'VPN: JED-Main - Connection',
        'VPN: RYD-Backup - Active',
        'VPN: RYD-Backup - Connection',
    ]);
});

it('retires sensors for a tunnel deleted from the firewall', function () {
    sophosOs(walkOf(['Keep-Me', 'Delete-Me']))->runVpnDiscovery();
    expect(SnmpSensor::live()->count())->toBe(4);

    // Next discovery: the firewall no longer reports the second tunnel.
    sophosOs(walkOf(['Keep-Me']))->runVpnDiscovery();

    expect(SnmpSensor::live()->count())->toBe(2)
        ->and(SnmpSensor::retired()->count())->toBe(2);

    // Retired, not deleted -- the rows and their history are still there.
    expect(SnmpSensor::count())->toBe(4);
});

it('never retires anything when the SNMP walk fails', function () {
    sophosOs(walkOf(['JED-Main', 'RYD-Backup']))->runVpnDiscovery();

    // A timeout returns false, which is indistinguishable from "no tunnels" if
    // you are not careful -- and blanking the board on a timeout would be worse
    // than showing a stale tunnel.
    sophosOs(false)->runVpnDiscovery();

    expect(SnmpSensor::live()->count())->toBe(4)
        ->and(SnmpSensor::retired()->count())->toBe(0);
});

it('never retires anything when the walk returns an empty set', function () {
    sophosOs(walkOf(['JED-Main']))->runVpnDiscovery();

    sophosOs([])->runVpnDiscovery();

    // An empty array is the same ambiguity as false. Discovery bails before the
    // retire step rather than guessing.
    expect(SnmpSensor::live()->count())->toBe(2);
});

it('un-retires a tunnel that comes back', function () {
    sophosOs(walkOf(['Flapping']))->runVpnDiscovery();
    // A different tunnel at a different table index, so Flapping genuinely
    // disappears rather than being renamed in place.
    sophosOs(walkOf(['Something-Else'], startIndex: 2))->runVpnDiscovery();

    expect(SnmpSensor::retired()->where('name', 'like', '%Flapping%')->count())->toBe(2);

    // Re-enabled on the firewall: it should simply come back, with its history.
    sophosOs(walkOf(['Flapping']))->runVpnDiscovery();

    expect(SnmpSensor::retired()->where('name', 'like', '%Flapping%')->count())->toBe(0)
        ->and(SnmpSensor::live()->where('name', 'like', '%Flapping%')->count())->toBe(2);
});

it('does not un-mute a tunnel an operator switched off', function () {
    sophosOs(walkOf(['Partner-Circuit']))->runVpnDiscovery();

    SnmpSensor::query()->update(['monitor_enabled' => false]);

    // Rediscovery must not undo an operator's decision -- otherwise a muted
    // tunnel would start alarming again on the next daily discovery.
    sophosOs(walkOf(['Partner-Circuit']))->runVpnDiscovery();

    expect(SnmpSensor::where('monitor_enabled', true)->count())->toBe(0);
});

it('renames in place when a tunnel reuses a table index', function () {
    sophosOs(walkOf(['Old-Name']))->runVpnDiscovery();
    sophosOs(walkOf(['New-Name']))->runVpnDiscovery();

    // Identity is the OID, which carries the firewall's table index. A new
    // tunnel occupying a freed index is therefore an update, not a create --
    // pre-existing createSensor() behaviour, recorded here so the retire logic
    // is not blamed for it. Nothing is retired, and the history carries over.
    expect(SnmpSensor::retired()->count())->toBe(0)
        ->and(SnmpSensor::live()->count())->toBe(2)
        ->and(SnmpSensor::pluck('name')->sort()->values()->all())
        ->toBe(['VPN: New-Name - Active', 'VPN: New-Name - Connection']);
});

it('only touches VPN sensors when retiring', function () {
    DB::table('snmp_sensors')->insert([
        'host_id' => 1, 'name' => 'CPU Load', 'oid' => '1.9.9.9',
        'sensor_group' => 'system', 'monitor_enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    sophosOs(walkOf(['JED-Main']))->runVpnDiscovery();
    sophosOs(walkOf(['Other'], startIndex: 2))->runVpnDiscovery();

    // The system sensor was never in the VPN walk and must not be collateral.
    expect(SnmpSensor::where('sensor_group', 'system')->whereNull('retired_at')->count())->toBe(1);
});
