<?php

use App\Http\Controllers\Admin\BranchHealthIndexController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * What happens when config/branch_health.php is not loaded.
 *
 * This deployment runs `php artisan config:cache`. A cache built before this
 * file existed does not contain it, so on the first request after a deploy
 * every config('branch_health.*') lookup returns null until someone re-caches.
 *
 * That must degrade, not 500: a board that renders with zeroes and a warning is
 * recoverable, a white screen during a deploy window is not.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    Cache::flush();
    View::getFinder()->prependLocation(base_path('tests/stubs/views'));
    View::flushFinderCache();
});

it('scores without fatalling when the config is missing entirely', function () {
    Fx::healthy(1, 'Jeddah');
    config()->set('branch_health', null);
    Cache::flush();

    $score = app(\App\Services\HealthScoringService::class)->scoreForBranch(1, fresh: true);

    // Not merely non-fatal -- still CORRECT. Falling back to zeroed tuning would
    // score a healthy branch at 0, or (worse) pass every MOS sample against a
    // threshold of 0 and report a healthy fleet that isn't.
    expect($score['total'])->toBe(100)
        ->and($score['status'])->toBe('healthy')
        ->and(collect($score['categories'])->pluck('max_points')->all())->toBe([40, 45, 15]);
});

it('renders the board without fatalling when the config is missing entirely', function () {
    Fx::healthy(1, 'Jeddah');
    config()->set('branch_health', null);
    Cache::flush();

    $html = app(BranchHealthIndexController::class)->index()->render();

    expect($html)->toContain('Branch Health Index')
        // The scoring model tab still documents the real weights.
        ->toContain('40 pts')->toContain('45 pts')->toContain('15 pts')
        ->toContain('caps it at 59');
});

it('still applies the real freshness windows with the config missing', function () {
    BranchHealthSchema::seedBranches([[1, 'Stale']]);
    // Pinged six hours ago: well outside the 3-minute window, so unknown.
    Fx::switches(1, up: 2, down: 0, pingedAt: now()->subHours(6));
    config()->set('branch_health', null);
    Cache::flush();

    $score = app(\App\Services\HealthScoringService::class)->scoreForBranch(1, fresh: true);
    $switches = collect($score['categories'])->firstWhere('key', 'network')['checks'];
    $check = collect($switches)->firstWhere('key', 'switch_reachability');

    // A zeroed freshness window would treat the stale ping as current and hand
    // out full marks -- the precise failure this whole model exists to prevent.
    expect($check['status'])->toBe('unknown')
        ->and($check['points'])->toBe(0.0);
});
