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

    expect($score)->toHaveKeys(['total', 'categories', 'status', 'cap_reasons']);
});

it('renders the board without fatalling when the config is missing entirely', function () {
    Fx::healthy(1, 'Jeddah');
    config()->set('branch_health', null);
    Cache::flush();

    $html = app(BranchHealthIndexController::class)->index()->render();

    expect($html)->toContain('Branch Health Index');
});
