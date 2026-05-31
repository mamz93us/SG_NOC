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

it('serves a marketing-branded login on the marketing host', function () {
    $routes = app('router')->getRoutes();

    $login = $routes->match(Illuminate\Http\Request::create('https://em.samirgroup.net/login', 'GET'));
    expect($login->getName())->toBe('portal.marketing.login');
    expect($login->getDomain())->toBe('em.samirgroup.net');
});

/**
 * Run the host-isolation middleware against a request that resolves to a route
 * with the given name, returning the response (or letting abort() throw).
 */
function marketingIsolation(string $url, ?string $routeName): \Symfony\Component\HttpFoundation\Response
{
    $request = Illuminate\Http\Request::create($url, 'GET');

    if ($routeName !== null) {
        $route = (new Illuminate\Routing\Route('GET', '/x', []))->name($routeName);
        $request->setRouteResolver(fn () => $route);
    }

    return (new App\Http\Middleware\EnforceMarketingHostIsolation)
        ->handle($request, fn ($r) => response('ok'));
}

it('404s NOC routes (portal hub, admin) on the marketing host', function () {
    expect(fn () => marketingIsolation('https://em.samirgroup.net/portal', 'portal.index'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    expect(fn () => marketingIsolation('https://em.samirgroup.net/admin/devices', 'admin.devices.index'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('allows the marketing portal, SSO and the 2FA flow on the marketing host', function () {
    $allowed = [
        'portal.marketing.dashboard',
        'portal.marketing.login',
        'portal.marketing.campaigns.index',
        'auth.microsoft',
        'two-factor.challenge',
        'admin.two-factor.setup',
        'email.unsubscribe.show',
    ];

    foreach ($allowed as $name) {
        expect(marketingIsolation('https://em.samirgroup.net/x', $name)->getContent())->toBe('ok');
    }
});

it('does not restrict the NOC host', function () {
    expect(marketingIsolation('https://noc.samirgroup.net/portal', 'portal.index')->getContent())->toBe('ok');
});
