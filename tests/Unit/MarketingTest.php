<?php

use App\Support\Marketing;

uses(Tests\TestCase::class);

beforeEach(fn () => Marketing::flush());
afterEach(fn () => Marketing::flush());

it('falls back to the default host when the marketing_domain column is absent', function () {
    // Unit tests don't migrate, so the settings table/column is absent here.
    // The resolver must not throw and must not leak a bogus value — notably the
    // SQLite double-quoted-identifier quirk, where selecting a missing column
    // returns the column name as a string literal. It must return the default.
    expect(Marketing::domain())->toBe('em.samirgroup.net');
});

it('builds absolute https urls on the marketing host', function () {
    expect(Marketing::url())->toBe('https://em.samirgroup.net/');
    expect(Marketing::url('campaigns'))->toBe('https://em.samirgroup.net/campaigns');
    expect(Marketing::url('/email/unsubscribe/x'))->toBe('https://em.samirgroup.net/email/unsubscribe/x');
});

it('routes the marketing host root to the dashboard and other hosts to welcome', function () {
    $routes = app('router')->getRoutes();

    // The marketing dashboard is domain-constrained; it must win for the em host.
    $em = $routes->match(Illuminate\Http\Request::create('https://em.samirgroup.net/', 'GET'));
    expect($em->getName())->toBe('portal.marketing.dashboard');
    expect($em->getDomain())->toBe('em.samirgroup.net');

    // Any other host falls through to the unconstrained welcome route.
    $noc = $routes->match(Illuminate\Http\Request::create('https://noc.samirgroup.net/', 'GET'));
    expect($noc->getName())->toBeNull();
    expect($noc->getDomain())->toBeNull();
});
