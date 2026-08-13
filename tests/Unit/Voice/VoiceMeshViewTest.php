<?php

use App\Http\Controllers\Admin\VoiceMeshController;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use App\Models\VoiceMeshRun;
use App\Services\NotificationService;
use App\Services\Voice\VoiceMeshMonitor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Tests\Support\VoiceMeshSchema;

/**
 * Renders each Voice Mesh page's own markup.
 *
 * `view:cache` only proves a Blade file *compiles*; it cannot catch a variable
 * the controller forgot to pass, which is the likeliest bug in a page this
 * data-heavy. These tests render for real against a stub layout (see
 * tests/stubs/views) so the admin chrome — authenticated user, settings
 * singleton, nav tree — doesn't have to be stood up.
 *
 * The full page behind auth, 2FA and the real layout still has to be eyeballed
 * against a running environment; this covers the part that a machine can.
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

    // Render this page's markup, not the whole admin shell.
    View::getFinder()->prependLocation(base_path('tests/stubs/views'));
    View::getFinder()->flush();

    // Force @can(...) true so the manage-gated markup renders too — otherwise
    // the node modal and the secret panel would never be exercised.
    Gate::before(fn () => true);
});

/** A mesh with one failing leg, so both the OK and FAIL branches render. */
function seedSweep(): void
{
    $codes = ['CAI', 'JED', 'RYD'];
    $results = [];

    foreach ($codes as $caller) {
        foreach ($codes as $dest) {
            if ($caller === $dest) {
                continue;
            }
            $ok = ! ($caller === 'JED' && $dest === 'RYD');
            $results[] = [
                'caller' => $caller, 'dest' => $dest, 'dest_ext' => '707', 'ok' => $ok,
                'rx_pkt' => $ok ? 289 : 0, 'duration_sec' => $ok ? 5.81 : 0.0,
                'reference_duration_sec' => 5.80,
                'reason' => $ok ? 'OK' : 'no RTP media received',
            ];
        }
    }

    app(VoiceMeshMonitor::class)->ingest([
        'runner_name' => 'test', 'ok' => false, 'results' => $results,
    ]);
}

it('renders the matrix with real data', function () {
    seedSweep();

    $html = app(VoiceMeshController::class)->index()->render();

    expect($html)->toContain('Voice Mesh')
        ->and($html)->toContain('vm-matrix')
        ->and($html)->toContain('CAI')
        ->and($html)->toContain('vm-ok')
        ->and($html)->toContain('vm-fail')
        ->and($html)->toContain('can call out')
        // The manage-gated blocks.
        ->and($html)->toContain('Rotate ingest secret')
        ->and($html)->toContain('Add branch')
        // The plaintext SIP password must never reach the browser.
        ->and($html)->not->toContain('secret-CAI');
});

it('renders the matrix before anything has ever reported', function () {
    $html = app(VoiceMeshController::class)->index()->render();

    expect($html)->toContain('Voice Mesh')
        ->and($html)->toContain('Not yet tested');
});

it('tells the operator when fewer than two branches are configured', function () {
    VoiceMeshNode::where('code', '!=', 'CAI')->delete();

    $html = app(VoiceMeshController::class)->index()->render();

    expect($html)->toContain('A mesh needs at least two branches');
});

it('renders a pair drill-down', function () {
    seedSweep();
    seedSweep();

    $pair = VoiceMeshPair::whereHas('caller', fn ($q) => $q->where('code', 'JED'))
        ->whereHas('dest', fn ($q) => $q->where('code', 'RYD'))
        ->firstOrFail();

    $html = app(VoiceMeshController::class)->pair($pair)->render();

    expect($html)->toContain('JED → RYD')
        ->and($html)->toContain('no RTP media received')
        ->and($html)->toContain('Success, 24 hours')
        ->and($html)->toContain('vm-spark');
});

it('renders a pair that has never been tested', function () {
    $nodes = VoiceMeshNode::ordered()->get();
    $pair = VoiceMeshPair::create([
        'caller_node_id' => $nodes[0]->id,
        'dest_node_id' => $nodes[1]->id,
    ]);

    $html = app(VoiceMeshController::class)->pair($pair)->render();

    expect($html)->toContain('No history yet');
});

it('renders the run log and a single run', function () {
    seedSweep();

    $list = app(VoiceMeshController::class)->runs()->render();
    expect($list)->toContain('Voice mesh run log')->and($list)->toContain('failures');

    $run = VoiceMeshRun::firstOrFail();
    $detail = app(VoiceMeshController::class)->run($run)->render();

    expect($detail)->toContain('Legs')
        ->and($detail)->toContain('no RTP media received')
        ->and($detail)->toContain('FAIL');
});

it('renders an empty run log', function () {
    expect(app(VoiceMeshController::class)->runs()->render())
        ->toContain('Nothing reported yet');
});

it('warns on the board when a sweep named an unknown branch code', function () {
    app(VoiceMeshMonitor::class)->ingest([
        'runner_name' => 'test', 'ok' => false,
        'results' => [
            ['caller' => 'CAI', 'dest' => 'JED', 'ok' => true, 'reason' => 'OK'],
            ['caller' => 'CAI', 'dest' => 'TYPO', 'ok' => false, 'reason' => 'x'],
        ],
    ]);

    $html = app(VoiceMeshController::class)->index()->render();

    expect($html)->toContain("don't exist here")
        ->and($html)->toContain('TYPO');
});
