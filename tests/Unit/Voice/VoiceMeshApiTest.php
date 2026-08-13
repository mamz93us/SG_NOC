<?php

use App\Models\Setting;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use App\Models\VoiceMeshResult;
use App\Models\VoiceMeshRun;
use App\Services\NotificationService;
use Tests\Support\VoiceMeshSchema;

/**
 * The two endpoints the prober talks to — real HTTP requests through the router,
 * middleware included.
 *
 * Lives under Unit/ despite being an HTTP test because tests/Pest.php applies
 * RefreshDatabase to everything in Feature/, and a full migrate is not possible
 * on the SQLite test connection (several migrations in this repo are MySQL-only
 * `MODIFY COLUMN`). Binding Tests\TestCase by hand here gets a booted app and
 * the test client without the migrate — the same workaround
 * tests/Unit/Network/TunnelWatchdogTest.php uses. The tables come from
 * VoiceMeshSchema.
 *
 * Requests originate from 127.0.0.1 in the test client, which satisfies
 * `internal.ip`; the non-internal case is exercised explicitly.
 */
uses(Tests\TestCase::class);

const SECRET = 'voice-mesh-test-secret';

beforeEach(function () {
    $this->mock(NotificationService::class)
        ->shouldReceive('notifyViaRules')->andReturnNull()->byDefault();

    VoiceMeshSchema::create();
    VoiceMeshSchema::seedNodes([
        ['CAI', '7076', '10.9.8.10'],
        ['JED', '7071', '10.1.8.10'],
    ]);

    $settings = Setting::get();
    $settings->voice_mesh_secret = SECRET;
    $settings->save();
});

function meshReport(array $overrides = []): array
{
    return array_merge([
        'runner_name' => 'rtp-mesh-check',
        'timestamp' => now()->toIso8601String(),
        'ok' => false,
        'results' => [
            [
                'caller' => 'CAI', 'dest' => 'JED', 'dest_ext' => '7071', 'ok' => true,
                'rx_pkt' => 289, 'duration_sec' => 5.81, 'reference_duration_sec' => 5.80,
                'reason' => 'OK',
            ],
            [
                'caller' => 'JED', 'dest' => 'CAI', 'dest_ext' => '7076', 'ok' => false,
                'rx_pkt' => 0, 'duration_sec' => 0.0, 'reference_duration_sec' => 5.80,
                'reason' => 'call never reached CONFIRMED (signalling failure)',
            ],
        ],
    ], $overrides);
}

// ── auth ─────────────────────────────────────────────────────────

it('rejects a report with no secret', function () {
    $this->postJson('/api/voice-mesh/report', meshReport())
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'unauthorized']);
});

it('rejects a report with the wrong secret', function () {
    $this->withHeader('X-Voice-Mesh-Secret', 'nope')
        ->postJson('/api/voice-mesh/report', meshReport())
        ->assertStatus(401);
});

it('fails closed when no secret is configured at all', function () {
    $settings = Setting::get();
    $settings->voice_mesh_secret = null;
    $settings->save();
    config(['services.voice_mesh.secret' => null]);

    $this->withHeader('X-Voice-Mesh-Secret', '')
        ->postJson('/api/voice-mesh/report', meshReport())
        ->assertStatus(401);
});

it('rejects a request from outside the host even with a valid secret', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport())
        ->assertStatus(403);
});

// ── config ───────────────────────────────────────────────────────

it('hands the prober the active branch list with credentials', function () {
    $response = $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->getJson('/api/voice-mesh/config')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $body = $response->json();

    expect($body['branches'])->toHaveCount(2)
        ->and($body['branches'][0]['name'])->toBe('CAI')
        ->and($body['branches'][0]['ext'])->toBe('7076')
        ->and($body['branches'][0]['sip_server'])->toBe('10.9.8.10')
        ->and($body['branches'][0]['sip_pass'])->toBe('secret-CAI')
        ->and($body['interval_minutes'])->toBe(30);
});

it('omits inactive branches from the config', function () {
    VoiceMeshNode::where('code', 'JED')->update(['is_active' => false]);

    $body = $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->getJson('/api/voice-mesh/config')->assertOk()->json();

    // One active node is not a mesh — an empty list plus a warning, so the
    // prober logs and no-ops rather than crash-looping its timer.
    expect($body['branches'])->toBe([])
        ->and($body)->toHaveKey('warning');
});

it('refuses the config without a secret', function () {
    $this->getJson('/api/voice-mesh/config')->assertStatus(401);
});

// ── report ───────────────────────────────────────────────────────

it('ingests a valid report into runs, pairs and results', function () {
    $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport())
        ->assertOk()
        ->assertJson(['ok' => true, 'pairs_recorded' => 2, 'pairs_ok' => 1]);

    expect(VoiceMeshRun::count())->toBe(1)
        ->and(VoiceMeshPair::count())->toBe(2)
        ->and(VoiceMeshResult::count())->toBe(2);

    $failing = VoiceMeshPair::whereHas('caller', fn ($q) => $q->where('code', 'JED'))->first();

    expect($failing->status)->toBe('fail')
        ->and($failing->last_reason)->toContain('signalling failure')
        ->and($failing->last_rx_pkt)->toBe(0);

    $passing = VoiceMeshPair::whereHas('caller', fn ($q) => $q->where('code', 'CAI'))->first();

    expect($passing->status)->toBe('ok')
        ->and((float) $passing->last_duration_sec)->toBe(5.81);
});

it('stores the raw payload for forensic replay', function () {
    $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport())->assertOk();

    expect(VoiceMeshRun::first()->payload['results'])->toHaveCount(2);
});

it('rejects a report with no results', function () {
    $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport(['results' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('results');
});

it('rejects a result row missing its endpoints', function () {
    $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport(['results' => [['ok' => true]]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['results.0.caller', 'results.0.dest']);
});

it('rejects an implausibly large payload rather than inserting it', function () {
    $rows = array_fill(0, 401, [
        'caller' => 'CAI', 'dest' => 'JED', 'ok' => true,
    ]);

    $this->withHeader('X-Voice-Mesh-Secret', SECRET)
        ->postJson('/api/voice-mesh/report', meshReport(['results' => $rows]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('results');
});
