<?php

use App\Http\Controllers\Admin\NocController;
use App\Services\HealthScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * The contract /admin/noc depends on.
 *
 * These would normally be Feature tests hitting the routes, but the Feature lane
 * binds RefreshDatabase and this repo's MySQL-only migrations cannot run on the
 * SQLite test connection. So the route definition is asserted against the router,
 * and the payload shape against the controller's collaborators, without an HTTP
 * round trip.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    Cache::flush();
});

// ─────────────────────────────────────────────────────────────────────
// Routing
// ─────────────────────────────────────────────────────────────────────

it('binds the branch route to a model rather than a bare id', function () {
    $route = Route::getRoutes()->getByName('admin.noc.branch');

    expect($route)->not->toBeNull()
        // The URL shape is unchanged; only the placeholder name is.
        ->and($route->uri())->toBe('admin/noc/branch/{branch}')
        // Implicit binding matches the URI segment name to the controller's
        // argument name. As {id} against branch(Branch $branch) it never fired,
        // so the controller received an empty model with a null id.
        ->and($route->parameterNames())->toBe(['branch']);

    $method = new ReflectionMethod(NocController::class, 'branch');
    expect($method->getParameters()[0]->getName())->toBe('branch')
        ->and($method->getParameters()[0]->getType()->getName())->toBe(\App\Models\Branch::class);
});

it('keeps the branch URL and route name stable', function () {
    BranchHealthSchema::seedBranches([[7, 'Jeddah']]);

    expect(route('admin.noc.branch', 7))->toEndWith('/admin/noc/branch/7');
});

it('still exposes the dashboard and dashboard-data routes under view-noc', function () {
    foreach (['admin.noc.dashboard' => 'admin/noc',
        'admin.noc.dashboard.data' => 'admin/noc/dashboard-data'] as $name => $uri) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} disappeared")
            ->and($route->uri())->toBe($uri)
            ->and($route->gatherMiddleware())->toContain('permission:view-noc');
    }
});

it('keeps event acknowledge and resolve behind manage-noc', function () {
    foreach (['admin.noc.events.acknowledge', 'admin.noc.events.resolve'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} disappeared")
            ->and($route->gatherMiddleware())->toContain('permission:manage-noc');
    }
});

// ─────────────────────────────────────────────────────────────────────
// Payload shape
// ─────────────────────────────────────────────────────────────────────

it('shapes each branch summary the dashboard JS expects', function () {
    Fx::healthy(1, 'Jeddah');

    // Mirrors the mapping in NocController::dashboardHeavyData().
    $payload = app(HealthScoringService::class)->allBranches()->map(fn ($b) => [
        'id' => $b->id,
        'name' => $b->name,
        'url' => route('admin.noc.branch', $b->id),
        'total' => $b->health['total'],
        'coverage_percent' => $b->health['coverage_percent'],
        'status' => $b->health['status'],
        'categories' => collect($b->health['categories'])->values(),
    ])->values();

    expect($payload)->toHaveCount(1);

    $row = $payload->first();
    expect($row['total'])->toBe(100)
        ->and($row['coverage_percent'])->toBe(100)
        ->and($row['status'])->toBe('excellent')
        ->and($row['url'])->toEndWith('/admin/noc/branch/1')
        // Three categories, in the order the badges render.
        ->and($row['categories']->pluck('key')->all())->toBe(['voip', 'network', 'devices'])
        ->and($row['categories']->pluck('max_points')->all())->toBe([40, 45, 15]);
});

it('re-indexes branches so the payload is a JSON array, not an object', function () {
    Fx::healthy(1, 'Alpha');
    BranchHealthSchema::seedBranches([[2, 'Beta']]);
    Fx::switches(2, up: 0, down: 3);

    // allBranches() sorts, which leaves sparse keys; PHP encodes those as a JSON
    // object and .map() in the dashboard JS then throws.
    $json = json_decode(
        json_encode(app(HealthScoringService::class)->allBranches()->map(fn ($b) => ['id' => $b->id])->values()),
        true
    );

    expect($json)->toBeArray()->and(array_keys($json))->toBe([0, 1]);
});

it('never leaks credentials or secrets into the score payload', function () {
    Fx::healthy(1);
    // A UCM failure whose error echoes back the request, as they do.
    \Illuminate\Support\Facades\DB::table('ucm_servers')->update([
        'last_health_ok' => false,
        'last_health_error' => 'auth failed for username=svc-ucm-acct password=hunter2sekret '
            .'cookie=sid-9f3ab1 challenge=deadbeefmd5 at https://boxuser:boxpw@ucm.local/api',
    ]);

    $json = json_encode(app(HealthScoringService::class)->scoreForBranch(1, fresh: true));

    // The values themselves must be gone — including credentials embedded in a
    // URL, which is how the UCM client reports a failed login.
    foreach (['svc-ucm-acct', 'hunter2sekret', 'sid-9f3ab1', 'deadbeefmd5', 'boxpw'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    // ...and no credential-bearing column may be serialized at all.
    foreach (['sip_pass', 'snmp_community', 'api_password', 'api_username'] as $column) {
        expect($json)->not->toContain($column);
    }

    // The error is still reported, just with the sensitive parts removed, so an
    // operator can still see that authentication is what failed.
    expect($json)->toContain('[redacted]');
});

it('exposes a working source link for every check', function () {
    Fx::healthy(1);

    $checks = collect(app(HealthScoringService::class)->scoreForBranch(1, fresh: true)['categories'])
        ->flatMap(fn ($c) => $c['checks']);

    // A failing check that does not link anywhere is a dead end for whoever is
    // on call, so this is part of the contract rather than a nicety.
    foreach ($checks as $check) {
        expect($check['portal_url'])->toBeString()->not->toBeEmpty();
        $path = parse_url($check['portal_url'], PHP_URL_PATH);
        expect(collect(Route::getRoutes())->contains(
            fn ($r) => '/'.ltrim($r->uri(), '/') === $path
        ))->toBeTrue("no route matches {$path} for check {$check['key']}");
    }
});
