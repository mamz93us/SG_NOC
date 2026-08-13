<?php

use App\Models\NocEvent;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use App\Models\VoiceMeshResult;
use App\Models\VoiceMeshRun;
use App\Services\NotificationService;
use App\Services\Voice\VoiceMeshMonitor;
use Tests\Support\VoiceMeshSchema;

/**
 * Ingest and roll-up, exercised against hand-built tables.
 *
 * Binds Tests\TestCase without RefreshDatabase for the reason spelled out in
 * VoiceMeshSchema: MySQL-only migrations in this repo can't run on the SQLite
 * test connection.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    $this->mock(NotificationService::class)
        ->shouldReceive('notifyViaRules')->andReturnNull()->byDefault();

    VoiceMeshSchema::create();
    VoiceMeshSchema::seedNodes([
        ['CAI', '7076', '10.9.8.10'],
        ['JED', '7071', '10.1.8.10'],
        ['RYD', '7072', '10.2.88.10'],
    ]);
});

function monitor(): VoiceMeshMonitor
{
    return app(VoiceMeshMonitor::class);
}

/** One leg's result row in the prober's wire shape. */
function leg(string $caller, string $dest, bool $ok, string $reason = 'OK'): array
{
    return [
        'caller' => $caller,
        'dest' => $dest,
        'dest_ext' => '70'.substr($dest, 0, 2),
        'ok' => $ok,
        'rx_pkt' => $ok ? 289 : 0,
        'duration_sec' => $ok ? 5.81 : 0.0,
        'reference_duration_sec' => 5.80,
        'reason' => $reason,
    ];
}

/** A full mesh where every leg passes except those named in $failing. */
function sweep(array $failing = []): array
{
    $codes = ['CAI', 'JED', 'RYD'];
    $results = [];

    foreach ($codes as $caller) {
        foreach ($codes as $dest) {
            if ($caller === $dest) {
                continue;
            }
            $ok = ! in_array("{$caller}|{$dest}", $failing, true);
            $results[] = leg($caller, $dest, $ok, $ok ? 'OK' : 'no RTP media received');
        }
    }

    return ['runner_name' => 'test', 'ok' => $failing === [], 'results' => $results];
}

it('records a clean sweep as one run, six legs and six pairs', function () {
    $run = monitor()->ingest(sweep());

    expect(VoiceMeshRun::count())->toBe(1)
        ->and($run->pairs_total)->toBe(6)
        ->and($run->pairs_ok)->toBe(6)
        ->and($run->pairs_failed)->toBe(0)
        ->and($run->ok)->toBeTrue()
        ->and(VoiceMeshPair::count())->toBe(6)
        ->and(VoiceMeshResult::count())->toBe(6)
        ->and(VoiceMeshNode::pluck('state')->unique()->all())->toBe(['up'])
        ->and(NocEvent::count())->toBe(0);
});

it('drops self-call rows without failing the run', function () {
    $payload = sweep();
    $payload['results'][] = leg('CAI', 'CAI', false, 'nonsense');

    $run = monitor()->ingest($payload);

    expect($run->pairs_total)->toBe(6)
        ->and($run->ok)->toBeTrue();
});

it('records unknown branch codes without creating phantom nodes', function () {
    $payload = sweep();
    $payload['results'][] = leg('CAI', 'NOPE', false, 'unreachable');

    $run = monitor()->ingest($payload);

    expect($run->unknown_nodes)->toBe(['NOPE'])
        ->and(VoiceMeshNode::count())->toBe(3)      // not 4
        ->and($run->pairs_total)->toBe(6)           // the other legs still ingested
        ->and($run->ok)->toBeFalse();               // but the run is not clean
});

it('holds the first failure of a single leg back from alerting', function () {
    monitor()->ingest(sweep(['CAI|JED']));

    $pair = VoiceMeshPair::first();

    expect($pair->consecutive_failures)->toBe(1)
        ->and(NocEvent::where('source_type', 'voice_mesh_pair_failed')->count())->toBe(0);
});

it('raises one leg event on the second consecutive failure', function () {
    monitor()->ingest(sweep(['CAI|JED']));
    monitor()->ingest(sweep(['CAI|JED']));

    $events = NocEvent::where('source_type', 'voice_mesh_pair_failed')->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->severity)->toBe('warning')
        ->and($events->first()->module)->toBe('voip')
        ->and($events->first()->title)->toContain('CAI → JED');
});

it('reports one caller_down and no leg events when a branch cannot call out', function () {
    // Every outbound leg from JED fails — its UCM is refusing our registration.
    // Naively this is 2 failing legs; at 7 branches it would be 6, and with the
    // inbound side too, 12. It must still be one alert.
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));

    expect(NocEvent::where('source_type', 'voice_mesh_caller_down')->count())->toBe(1)
        ->and(NocEvent::where('source_type', 'voice_mesh_pair_failed')->count())->toBe(0)
        // Degraded, not down: JED's INBOUND legs still passed, so it is
        // reachable — it just can't originate. `down` is reserved for a node
        // whose every leg failed in both directions.
        ->and(VoiceMeshNode::where('code', 'JED')->first()->state)->toBe('degraded');
});

it('marks a node down only when its legs fail in both directions', function () {
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD', 'CAI|JED', 'RYD|JED']));

    expect(VoiceMeshNode::where('code', 'JED')->first()->state)->toBe('down')
        ->and(VoiceMeshNode::where('code', 'CAI')->first()->state)->toBe('degraded');
});

it('reports dest_down when every healthy caller fails to reach one branch', function () {
    monitor()->ingest(sweep(['CAI|RYD', 'JED|RYD']));

    $events = NocEvent::where('source_type', 'voice_mesh_dest_down')->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->title)->toContain('RYD')
        ->and(NocEvent::where('source_type', 'voice_mesh_pair_failed')->count())->toBe(0);
});

it('does not blame a destination when the only callers that failed are themselves down', function () {
    // JED can't call anyone. That means JED->RYD failing says nothing about RYD,
    // so RYD must not be reported unreachable on the strength of it.
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));

    expect(NocEvent::where('source_type', 'voice_mesh_dest_down')->count())->toBe(0);
});

it('resolves the event when the mesh recovers', function () {
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
    expect(NocEvent::where('source_type', 'voice_mesh_caller_down')->where('status', 'open')->count())->toBe(1);

    monitor()->ingest(sweep());

    expect(NocEvent::where('source_type', 'voice_mesh_caller_down')->where('status', 'open')->count())->toBe(0)
        ->and(NocEvent::where('source_type', 'voice_mesh_caller_down')->where('status', 'resolved')->count())->toBe(1);
});

it('re-opens the same event inside the flap window instead of minting another', function () {
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
    monitor()->ingest(sweep());                              // resolved
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));        // fails again straight away

    expect(NocEvent::where('source_type', 'voice_mesh_caller_down')->count())->toBe(1)
        ->and(NocEvent::where('source_type', 'voice_mesh_caller_down')->first()->status)->toBe('open');
});

it('sends exactly one notification per incident however many sweeps it spans', function () {
    $this->mock(NotificationService::class)
        ->shouldReceive('notifyViaRules')->once()->andReturnNull();

    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
    monitor()->ingest(sweep(['JED|CAI', 'JED|RYD']));
});

it('clamps a wildly wrong prober clock back to now', function () {
    $payload = sweep();
    $payload['timestamp'] = '2019-01-01T00:00:00';

    $run = monitor()->ingest($payload);

    expect($run->reported_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('keeps a plausible prober timestamp', function () {
    $payload = sweep();
    $payload['timestamp'] = now()->subMinutes(3)->toIso8601String();

    $run = monitor()->ingest($payload);

    expect($run->reported_at->format('H:i'))->toBe(now()->subMinutes(3)->format('H:i'));
});

it('leaves legs absent from a short run alone rather than failing them', function () {
    monitor()->ingest(sweep());

    // A run that only managed CAI's legs.
    monitor()->ingest([
        'runner_name' => 'test',
        'ok' => true,
        'results' => [leg('CAI', 'JED', true), leg('CAI', 'RYD', true)],
    ]);

    $untouched = VoiceMeshPair::whereHas('caller', fn ($q) => $q->where('code', 'JED'))->get();

    expect($untouched->pluck('status')->unique()->all())->toBe(['ok']);
});

it('raises a stale event when no report arrives in time and clears it when one does', function () {
    monitor()->ingest(sweep());

    VoiceMeshRun::query()->update(['received_at' => now()->subHours(4)]);
    monitor()->checkStale();

    expect(NocEvent::where('source_type', 'voice_mesh_stale')->where('status', 'open')->count())->toBe(1);

    monitor()->ingest(sweep());
    monitor()->checkStale();

    expect(NocEvent::where('source_type', 'voice_mesh_stale')->where('status', 'open')->count())->toBe(0);
});

it('says nothing on a fresh install that has never reported', function () {
    expect(monitor()->checkStale())->toBeNull()
        ->and(NocEvent::count())->toBe(0);
});

it('prunes history past the retention window', function () {
    monitor()->ingest(sweep());
    VoiceMeshResult::query()->update(['checked_at' => now()->subDays(60)]);
    VoiceMeshRun::query()->update(['received_at' => now()->subDays(60)]);

    monitor()->ingest(sweep());     // a recent one that must survive

    $deleted = monitor()->prune(30);

    expect($deleted['results'])->toBe(6)
        ->and($deleted['runs'])->toBe(1)
        ->and(VoiceMeshResult::count())->toBe(6)
        ->and(VoiceMeshRun::count())->toBe(1);
});
