<?php

use App\Models\BranchTunnel;
use App\Models\NocEvent;
use App\Models\TunnelHealthCheck;
use App\Models\TunnelProbe;
use App\Services\Network\TunnelWatchdog;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The watchdog's roll-up logic, exercised against stubbed transports.
 *
 * Lives in Unit/ and binds Tests\TestCase by hand so it gets a booted Laravel
 * app WITHOUT RefreshDatabase: several migrations in this repo are MySQL-only
 * (`MODIFY COLUMN`) and blow up on the SQLite connection phpunit.xml configures,
 * so a full migrate is not available. The four tables under test are created
 * directly in beforeEach instead.
 */
uses(Tests\TestCase::class);

/** Watchdog with the network replaced by a fixture. */
function watchdog(array $icmp, array $tcp = []): TunnelWatchdog
{
    return new class(app(NotificationService::class), $icmp, $tcp) extends TunnelWatchdog
    {
        public function __construct(NotificationService $n, private array $icmpFixture, private array $tcpFixture)
        {
            parent::__construct($n);
        }

        protected function icmpBatch(array $hosts): array
        {
            return $this->icmpFixture;
        }

        protected function tcpBatch(array $targets): array
        {
            return $this->tcpFixture;
        }
    };
}

function up(int $latency = 3): array
{
    return ['alive' => true, 'latency' => $latency];
}

function down(): array
{
    return ['alive' => false, 'latency' => null];
}

beforeEach(function () {
    // Never actually send anything.
    $this->mock(NotificationService::class)->shouldReceive('notifyViaRules')->andReturnNull()->byDefault();

    foreach (['tunnel_health_checks', 'tunnel_probes', 'branch_tunnels', 'noc_events'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branch_tunnels', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('branch_id')->nullable();
        $t->string('firewall_ip', 45);
        $t->boolean('is_active')->default(true);
        $t->string('state', 20)->default('unknown');
        $t->timestamp('state_changed_at')->nullable();
        $t->string('ping_status')->default('unknown');
        $t->unsignedInteger('ping_latency_ms')->nullable();
        $t->timestamp('last_ping_at')->nullable();
        $t->unsignedSmallInteger('consecutive_failures')->default(0);
        $t->unsignedInteger('sort_order')->default(0);
        $t->timestamps();
    });

    Schema::create('tunnel_probes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('branch_tunnel_id');
        $t->string('label');
        $t->string('target', 45);
        $t->string('check_type', 10)->default('icmp');
        $t->unsignedSmallInteger('port')->nullable();
        $t->boolean('is_active')->default(true);
        $t->string('status', 20)->default('unknown');
        $t->unsignedInteger('latency_ms')->nullable();
        $t->timestamp('last_checked_at')->nullable();
        $t->timestamp('status_changed_at')->nullable();
        $t->unsignedSmallInteger('consecutive_failures')->default(0);
        $t->unsignedInteger('sort_order')->default(0);
        $t->timestamps();
    });

    Schema::create('tunnel_health_checks', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('branch_tunnel_id');
        $t->string('state', 20);
        $t->unsignedSmallInteger('probes_up')->default(0);
        $t->unsignedSmallInteger('probes_total')->default(0);
        $t->unsignedInteger('latency_ms')->nullable();
        $t->timestamp('checked_at');
    });

    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module')->nullable();
        $t->string('entity_type')->nullable();
        $t->string('entity_id')->nullable();
        $t->string('source_type')->nullable();
        $t->string('source_id')->nullable();
        $t->unsignedInteger('cooldown_minutes')->nullable();
        $t->timestamp('email_sent_at')->nullable();
        $t->string('severity')->nullable();
        $t->string('title')->nullable();
        $t->text('message')->nullable();
        $t->timestamp('first_seen')->nullable();
        $t->timestamp('last_seen')->nullable();
        $t->string('status')->default('open');
        $t->unsignedBigInteger('acknowledged_by')->nullable();
        $t->unsignedBigInteger('resolved_by')->nullable();
        $t->timestamp('resolved_at')->nullable();
        $t->timestamps();
    });
});

/** Builds JED as it actually is: firewall on 10.1.0.1, voice VLAN on 10.1.8.x. */
function jed(): BranchTunnel
{
    $tunnel = BranchTunnel::create(['name' => 'JED', 'firewall_ip' => '10.1.0.1']);

    $tunnel->probes()->create(['label' => 'Voice VLAN core', 'target' => '10.1.8.5']);
    $tunnel->probes()->create([
        'label' => 'UCM API', 'target' => '10.1.8.10',
        'check_type' => TunnelProbe::TYPE_TCP, 'port' => 8089,
    ]);

    return $tunnel;
}

it('reports up when the gateway and every probe answer', function () {
    $tunnel = jed();

    watchdog(['10.1.0.1' => up(2), '10.1.8.5' => up(9)], ['probe-2' => up(14)])->run();

    expect($tunnel->fresh()->state)->toBe(BranchTunnel::STATE_UP);
    expect(NocEvent::count())->toBe(0);
});

it('reports degraded — not up — when the gateway answers but a carried subnet is dark', function () {
    // This is the JED failure of 2026-07-05: 10.1.0.1 replied for a month while
    // 10.1.8.0/24 was missing from the tunnel's traffic selector.
    $tunnel = jed();

    watchdog(['10.1.0.1' => up(2), '10.1.8.5' => down()], ['probe-2' => down()])->run();

    $tunnel = $tunnel->fresh();
    expect($tunnel->state)->toBe(BranchTunnel::STATE_DEGRADED);
    expect($tunnel->ping_status)->toBe('up');   // the gateway really is reachable

    $event = NocEvent::where('source_type', 'tunnel_degraded')->first();
    expect($event)->not->toBeNull();
    expect($event->severity)->toBe('warning');
    expect($event->message)->toContain('10.1.8.5')->toContain('10.1.8.10:8089');
});

it('marks probes individually so the failing subnet is identifiable', function () {
    $tunnel = jed();

    watchdog(['10.1.0.1' => up(), '10.1.8.5' => down()], ['probe-2' => up(11)])->run();

    $probes = $tunnel->fresh()->probes->keyBy('label');
    expect($probes['Voice VLAN core']->status)->toBe('down');
    expect($probes['UCM API']->status)->toBe('up');
    expect($probes['UCM API']->latency_ms)->toBe(11);
});

it('waits for consecutive gateway failures before raising an outage', function () {
    $tunnel = jed();
    $fixture = ['10.1.0.1' => down(), '10.1.8.5' => down()];

    watchdog($fixture, ['probe-2' => down()])->run();

    expect($tunnel->fresh()->state)->toBe(BranchTunnel::STATE_DOWN);
    expect(NocEvent::where('source_type', 'tunnel_down')->count())
        ->toBe(0, 'a single dropped packet must not page anyone');

    watchdog($fixture, ['probe-2' => down()])->run();

    expect(NocEvent::where('source_type', 'tunnel_down')->count())->toBe(1);
});

it('does not re-notify while an outage stays open', function () {
    $tunnel = jed();
    $fixture = ['10.1.0.1' => down(), '10.1.8.5' => down()];

    $this->mock(NotificationService::class)
        ->shouldReceive('notifyViaRules')->once()   // opening edge only
        ->andReturnNull();

    foreach (range(1, 4) as $_) {
        watchdog($fixture, ['probe-2' => down()])->run();
    }

    expect(NocEvent::where('source_type', 'tunnel_down')->where('status', 'open')->count())->toBe(1);
});

it('resolves the outage and opens a degraded warning when only the gateway comes back', function () {
    $tunnel = jed();
    $downFixture = ['10.1.0.1' => down(), '10.1.8.5' => down()];

    watchdog($downFixture, ['probe-2' => down()])->run();
    watchdog($downFixture, ['probe-2' => down()])->run();
    expect(NocEvent::where('source_type', 'tunnel_down')->where('status', 'open')->count())->toBe(1);

    // Gateway recovers, subnet still unreachable.
    watchdog(['10.1.0.1' => up(), '10.1.8.5' => down()], ['probe-2' => down()])->run();

    expect(NocEvent::where('source_type', 'tunnel_down')->where('status', 'open')->count())
        ->toBe(0, 'the outage is over');
    expect(NocEvent::where('source_type', 'tunnel_degraded')->where('status', 'open')->count())
        ->toBe(1, 'but partial reachability must not look healthy');
});

it('resolves everything once the tunnel is fully up again', function () {
    jed();
    $downFixture = ['10.1.0.1' => down(), '10.1.8.5' => down()];

    watchdog($downFixture, ['probe-2' => down()])->run();
    watchdog($downFixture, ['probe-2' => down()])->run();

    watchdog(['10.1.0.1' => up(), '10.1.8.5' => up()], ['probe-2' => up()])->run();

    expect(NocEvent::where('status', 'open')->count())->toBe(0);
});

it('treats a tunnel with no probes as gateway-only', function () {
    $tunnel = BranchTunnel::create(['name' => 'KBR', 'firewall_ip' => '10.3.0.1']);

    watchdog(['10.3.0.1' => up(5)])->run();

    expect($tunnel->fresh()->state)->toBe(BranchTunnel::STATE_UP);
});

it('skips paused probes and paused tunnels', function () {
    $tunnel = jed();
    $tunnel->probes()->update(['is_active' => false]);

    watchdog(['10.1.0.1' => up()])->run();
    expect($tunnel->fresh()->state)->toBe(BranchTunnel::STATE_UP);

    $tunnel->update(['is_active' => false]);
    $before = $tunnel->fresh()->updated_at;

    watchdog(['10.1.0.1' => down()])->run();
    expect($tunnel->fresh()->state)->toBe(BranchTunnel::STATE_UP);
    expect($tunnel->fresh()->updated_at->eq($before))->toBeTrue();
});

it('appends one history row per tunnel per sweep', function () {
    $tunnel = jed();

    watchdog(['10.1.0.1' => up(4), '10.1.8.5' => up()], ['probe-2' => down()])->run();

    $check = TunnelHealthCheck::where('branch_tunnel_id', $tunnel->id)->sole();
    expect($check->state)->toBe(BranchTunnel::STATE_DEGRADED);
    expect($check->probes_up)->toBe(1);
    expect($check->probes_total)->toBe(2);
    expect($check->latency_ms)->toBe(4);
});

it('reads ping output as down when every packet was lost', function () {
    // The trap: some ping builds still print an min/avg/max line alongside 100%
    // loss. Latency present must never be mistaken for reachability.
    $parser = new class(app(NotificationService::class)) extends TunnelWatchdog
    {
        public function parse(string $output, bool $win = false): array
        {
            return $this->parsePing($output, $win);
        }
    };

    $alive = <<<'OUT'
    --- 10.9.8.1 ping statistics ---
    2 packets transmitted, 2 received, 0% packet loss, time 1002ms
    rtt min/avg/max/mdev = 3.101/3.884/4.668/0.783 ms
    OUT;

    $dead = <<<'OUT'
    --- 10.1.8.10 ping statistics ---
    2 packets transmitted, 0 received, 100% packet loss, time 1015ms
    OUT;

    $lyingDead = <<<'OUT'
    --- 10.1.8.10 ping statistics ---
    2 packets transmitted, 0 received, 100% packet loss, time 1015ms
    rtt min/avg/max/mdev = 9.000/9.000/9.000/0.000 ms
    OUT;

    expect($parser->parse($alive))->toBe(['alive' => true, 'latency' => 4]);
    expect($parser->parse($dead))->toBe(['alive' => false, 'latency' => null]);
    expect($parser->parse($lyingDead))->toBe(['alive' => false, 'latency' => null]);

    // Windows phrasing differs: "(0% loss)" and "Average = 4ms".
    $win = "Ping statistics for 10.9.8.1:\r\n    Packets: Sent = 2, Received = 2, Lost = 0 (0% loss),\r\n"
        ."Approximate round trip times in milli-seconds:\r\n    Minimum = 3ms, Maximum = 5ms, Average = 4ms";
    expect($parser->parse($win, true))->toBe(['alive' => true, 'latency' => 4]);
});

it('computes uptime from the fully-up share of recent checks', function () {
    $tunnel = jed();

    watchdog(['10.1.0.1' => up(), '10.1.8.5' => up()], ['probe-2' => up()])->run();
    watchdog(['10.1.0.1' => up(), '10.1.8.5' => up()], ['probe-2' => up()])->run();
    watchdog(['10.1.0.1' => up(), '10.1.8.5' => down()], ['probe-2' => up()])->run();
    watchdog(['10.1.0.1' => up(), '10.1.8.5' => down()], ['probe-2' => up()])->run();

    expect($tunnel->fresh()->uptimePercent())->toBe(50.0);
});
