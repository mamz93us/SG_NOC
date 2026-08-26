<?php

use App\Http\Controllers\Admin\BranchHealthIndexController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * The Branch Health Index board.
 *
 * Renders the real template against real scores rather than trusting
 * view:cache, which compiles templates without executing them and has twice
 * missed render-time faults in this codebase.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    Cache::flush();

    // The real layouts.admin needs an authenticated user, roles and permissions;
    // rendering it would test the chrome rather than the board.
    View::getFinder()->prependLocation(base_path('tests/stubs/views'));
    View::flushFinderCache();
});

/** The board, rendered exactly as the controller assembles it. */
function boardHtml(): string
{
    $view = app(BranchHealthIndexController::class)->index();

    return $view->render();
}

it('exposes the board under view-noc without disturbing the other NOC routes', function () {
    $route = Route::getRoutes()->getByName('admin.noc.health');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('admin/noc/health')
        ->and($route->gatherMiddleware())->toContain('permission:view-noc');

    // The pages it sits beside must still be there.
    foreach (['admin.noc.dashboard', 'admin.noc.branch', 'admin.noc.dashboard.data'] as $name) {
        expect(Route::getRoutes()->getByName($name))->not->toBeNull("{$name} disappeared");
    }
});

it('renders the fleet roll-up, scoreboard and both other tabs', function () {
    Fx::healthy(1, 'Jeddah');
    BranchHealthSchema::seedBranches([[2, 'Riyadh']]);
    Fx::switches(2, up: 1, down: 3);
    Fx::printers(2, up: 1, down: 0, tonerPercent: 60);

    $html = boardHtml();

    expect($html)->toContain('Branch Health Index')
        ->toContain('Fleet score')
        ->toContain('Band distribution')
        // The three tabs.
        ->toContain('Scoreboard')->toContain('Check matrix')->toContain('Scoring model')
        // Categories, not the retired Identity/NW/IT badges.
        ->toContain('VoIP')->toContain('Network')->toContain('Devices')
        ->and($html)->not->toContain('ID:')
        // Both branches reach the client payload.
        ->and($html)->toContain('Jeddah')->toContain('Riyadh');
});

it('documents the same weights it actually applies', function () {
    Fx::healthy(1);

    $html = boardHtml();

    // The Scoring model tab reads config directly, so it cannot drift from the
    // weights being scored.
    expect($html)->toContain('40 pts')->toContain('45 pts')->toContain('15 pts');

    // Every check gets a short handle operators can say out loud.
    foreach (['V1', 'V4', 'N1', 'N5', 'D1', 'D3'] as $code) {
        expect($html)->toContain($code);
    }
});

it('states the caps and the coverage floor on the model tab', function () {
    Fx::healthy(1);

    // Collapse whitespace first: the prose wraps across source lines, and the
    // point is that the page states the real config values, not how it wrapped.
    $text = preg_replace('/\s+/', ' ', boardHtml());

    expect($text)
        ->toContain('caps it at 59')
        ->toContain('caps it at 79')
        ->toContain('fewer than 50 measurable');
});

it('ships each branch score to the client with its per-check detail', function () {
    Fx::healthy(1, 'Jeddah');

    $html = boardHtml();

    // The drawer is client-side, so the checks have to be in the payload.
    expect($html)->toContain('UCM Reachable')
        ->toContain('Biometric Devices Reachable')
        ->toContain('Printer Toner Above 30%')
        ->toContain('cap_reasons');
});

it('carries cap reasons through to the board', function () {
    Fx::healthy(1, 'Jeddah');
    Fx::criticalAlert(1);

    $html = boardHtml();

    expect($html)->toContain('open critical firewall\/Sophos alert')
        ->and($html)->toContain('"capped":true');
});

it('shows a barely-monitored branch as unknown rather than healthy', function () {
    BranchHealthSchema::seedBranches([[1, 'Barely']]);
    Fx::switches(1, up: 2, down: 0);

    $html = boardHtml();

    // 16 of 100 points measurable — below the floor, so no health claim.
    expect($html)->toContain('"status":"unknown"')
        ->and($html)->not->toContain('"status":"healthy"');
});

it('escapes branch names before they reach the client payload', function () {
    Fx::healthy(1, '</script><img src=x onerror=alert(1)>');

    $html = boardHtml();

    // Blade's @json sets JSON_HEX_TAG, so a branch name cannot close the script
    // block it is embedded in. The payload does still contain the literal text
    // "onerror=alert(1)" -- inside a JSON string that is inert, and the safety
    // comes entirely from the angle brackets being escaped.
    expect($html)->not->toContain('</script><img')
        ->and($html)->toContain('\u003C');
});

it('renders with no branches at all', function () {
    $html = boardHtml();

    expect($html)->toContain('Branch Health Index')
        ->and($html)->toContain('Fleet score');
});

it('uses the voice mesh code for a branch and falls back to a padded id', function () {
    Fx::healthy(1, 'Jeddah');            // seeds a mesh node coded B1
    BranchHealthSchema::seedBranches([[7, 'Unlinked']]);

    DB::table('voice_mesh_nodes')->where('branch_id', 1)->update(['code' => 'JED']);
    Cache::flush();

    $html = boardHtml();

    expect($html)->toContain('JED')     // real operator-facing code
        ->toContain('BR-07');           // fallback for a branch with no node
});
