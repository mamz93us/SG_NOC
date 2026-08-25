<?php

use App\Services\HealthScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * Actually renders the Blade the score feeds.
 *
 * `view:cache` compiles templates but does not execute them, and this codebase
 * has been bitten twice by errors that only appear at render time: a `{{TOKEN}}`
 * placeholder compiling to an echo of an undefined constant, and a directive
 * written flush against a word (`NOC@if(...)`) never being compiled while its
 * `@endif` was — leaving an unbalanced block. Both passed view:cache and threw
 * a 500 in production.
 *
 * So these render the real templates against real score data.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    Cache::flush();

    // Shadow layouts.admin with a bare stub. The real layout needs an
    // authenticated user, roles, permissions and the settings singleton —
    // rendering it would be testing the chrome, not the page under test.
    View::getFinder()->prependLocation(base_path('tests/stubs/views'));
    View::flushFinderCache();
});

/** Everything NocController::branch() hands the view. */
function branchViewData(int $branchId): array
{
    $branch = \App\Models\Branch::find($branchId);

    return [
        'branch' => $branch,
        'score' => app(HealthScoringService::class)->scoreForBranch($branchId, fresh: true),
        'switches' => collect(),
        'devices' => collect(),
        'printers' => collect(),
        'branchTunnels' => \App\Models\BranchTunnel::where('branch_id', $branchId)->with('activeProbes')->get(),
        'ispConns' => collect(),
        'monitorHosts' => collect(),
        'landlines' => collect(),
        'ipCount' => 0,
        'employees' => collect(),
        'accessPoints' => collect(),
        'openAlerts' => collect(),
        'openIncidents' => collect(),
        'dhcpLeases' => collect(),
        'subnets' => collect(),
        'sophosFirewalls' => collect(),
        'sophosVpnTunnels' => collect(),
    ];
}

it('renders the branch drill-down for a healthy branch', function () {
    Fx::healthy(1, 'Jeddah');

    $html = view('admin.noc.branch', branchViewData(1))->render();

    // The three fixed-weight categories, not the old four modules.
    expect($html)->toContain('VoIP')->toContain('Network')->toContain('Devices')
        ->and($html)->not->toContain('Identity Health')
        ->and($html)->not->toContain('Average of all 4 module scores');

    // The breakdown, with a row per check.
    expect($html)->toContain('Health Check Breakdown')
        ->toContain('UCM Reachable')
        ->toContain('Biometric Devices Reachable')
        ->toContain('Printer Toner Above 30%');

    expect($html)->toContain('Overall Branch Health');
});

it('renders cap reasons prominently when a branch is capped', function () {
    Fx::healthy(1, 'Jeddah');
    Fx::criticalAlert(1);

    $html = view('admin.noc.branch', branchViewData(1))->render();

    expect($html)->toContain('Score capped at 79')
        ->toContain('open critical firewall/Sophos alert');
});

it('renders a branch with no telemetry at all without erroring', function () {
    BranchHealthSchema::seedBranches([[1, 'Empty']]);

    $html = view('admin.noc.branch', branchViewData(1))->render();

    // Every ratio check is unknown, and the page must say so rather than
    // rendering a blank or a misleading green.
    expect($html)->toContain('Unknown')
        ->toContain('unmonitored')
        ->and($html)->not->toContain('Excellent');
});

it('shows the degraded subnets behind a reachable firewall', function () {
    BranchHealthSchema::seedBranches([[1, 'Jeddah']]);
    $tunnelId = Fx::firewall(1, state: 'degraded');

    \Illuminate\Support\Facades\DB::table('tunnel_probes')->insert([
        'branch_tunnel_id' => $tunnelId,
        'label' => '10.1.8.0/24',
        'target' => '10.1.8.10',
        'is_active' => true,
        'status' => 'down',
        'last_checked_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $html = view('admin.noc.branch', branchViewData(1))->render();

    // The JED case: the gateway answered for a month while a whole subnet was
    // dark. A degraded tunnel must never render as simply up.
    expect($html)->toContain('Degraded')
        ->toContain('Unreachable subnets')
        ->toContain('10.1.8.0/24');
});

it('renders the welcome branch-health widget from the score service', function () {
    Fx::healthy(1, 'Jeddah');
    BranchHealthSchema::seedBranches([[2, 'Riyadh']]);
    Fx::switches(2, up: 0, down: 4);

    $branches = app(HealthScoringService::class)->allBranches();
    $scored = $branches->filter(fn ($b) => $b->health['normalized_percent'] !== null);

    $html = view('admin.welcome.branch-health', ['health' => [
        'total' => $branches->count(),
        'critical' => $branches->filter(fn ($b) => $b->health['status'] === 'critical')->count(),
        'healthy' => $branches->filter(fn ($b) => in_array($b->health['status'], ['excellent', 'good'], true))->count(),
        'average' => (int) round($scored->avg(fn ($b) => $b->health['normalized_percent'])),
        'worst' => $branches->take(5)->map(fn ($b) => [
            'id' => $b->id, 'name' => $b->name,
            'total' => $b->health['total'], 'status' => $b->health['status'],
            'capped' => $b->health['cap_reasons'] !== [],
        ])->values(),
    ]])->render();

    expect($html)->toContain('Branch Health')
        // No longer claims to be ISP status.
        ->toContain('VoIP, network and device health across sites')
        ->and($html)->not->toContain('ISP link status across sites')
        // Worst-first, and each entry links to its drill-down.
        ->and($html)->toContain('Riyadh')
        ->and($html)->toContain('/admin/noc/branch/2');
});

it('escapes branch names before they reach the dashboard markup', function () {
    Fx::healthy(1, '<script>alert(1)</script>');

    $html = view('admin.welcome.branch-health', ['health' => [
        'total' => 1, 'critical' => 0, 'healthy' => 1, 'average' => 100,
        'worst' => collect([[
            'id' => 1, 'name' => '<script>alert(1)</script>',
            'total' => 100, 'status' => 'excellent', 'capped' => false,
        ]]),
    ]])->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
