<?php

use App\Support\VCard;

uses(Tests\TestCase::class);

it('resolves the card host and builds canonical urls on it', function () {
    expect(VCard::domain())->toBe('vcard.samirgroup.net');
    expect(VCard::url())->toBe('https://vcard.samirgroup.net/');
    expect(VCard::cardUrl('abc-123'))->toBe('https://vcard.samirgroup.net/card/abc-123');
});

it('falls back to the request host when the subdomain is disabled', function () {
    config()->set('vcard.enabled', false);

    expect(VCard::cardUrl('abc-123'))->toBe(url('/card/abc-123'));
});

it('routes the card host root to the signed-in card landing', function () {
    $routes = app('router')->getRoutes();

    $root = $routes->match(Illuminate\Http\Request::create('https://vcard.samirgroup.net/', 'GET'));
    expect($root->getName())->toBe('vcard.mine');
    expect($root->getDomain())->toBe('vcard.samirgroup.net');

    $login = $routes->match(Illuminate\Http\Request::create('https://vcard.samirgroup.net/login', 'GET'));
    expect($login->getName())->toBe('vcard.login');
});

/**
 * Run the host-isolation middleware against a request that resolves to a route
 * with the given name, returning the response (or letting abort() throw).
 */
function vcardIsolation(string $url, ?string $routeName): \Symfony\Component\HttpFoundation\Response
{
    $request = Illuminate\Http\Request::create($url, 'GET');

    if ($routeName !== null) {
        $route = (new Illuminate\Routing\Route('GET', '/x', []))->name($routeName);
        $request->setRouteResolver(fn () => $route);
    }

    return (new App\Http\Middleware\EnforceVcardHostIsolation)
        ->handle($request, fn ($r) => response('ok'));
}

it('404s everything that is not a card on the card host', function () {
    $denied = [
        'portal.index',
        'admin.employees.index',
        'admin.settings.index',
        'public.contacts',
        'portal.marketing.dashboard',
        // 2FA pages have no business here — this host skips 2FA entirely.
        'two-factor.challenge',
    ];

    foreach ($denied as $name) {
        expect(fn () => vcardIsolation('https://vcard.samirgroup.net/x', $name))
            ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    }
});

it('allows the card pages, sign-in and sign-out on the card host', function () {
    $allowed = [
        'vcard.mine',
        'vcard.login',
        'vcard.logout',
        'employee.card.show',
        'employee.card.vcard',
        'employee.card.wallet',
        'auth.microsoft',
        'logout',
    ];

    foreach ($allowed as $name) {
        expect(vcardIsolation('https://vcard.samirgroup.net/x', $name)->getContent())->toBe('ok');
    }
});

it('does not restrict the NOC host', function () {
    expect(vcardIsolation('https://noc.samirgroup.net/admin', 'admin.settings.index')->getContent())->toBe('ok');
});

/**
 * Run RequireTwoFactor for a 2FA-enrolled user whose session has NOT passed the
 * challenge, on the given host.
 */
function twoFactorGate(string $url): \Symfony\Component\HttpFoundation\Response
{
    $user = new class
    {
        public function hasTwoFactorEnabled(): bool
        {
            return true;
        }
    };

    $request = Illuminate\Http\Request::create($url, 'GET');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/x', []))->name('some.route'));

    return (new App\Http\Middleware\RequireTwoFactor)
        ->handle($request, fn ($r) => response('ok'));
}

it('skips 2FA on the card host but still enforces it on NOC', function () {
    // The card host is SSO-only by design — it serves nothing but a business card.
    expect(twoFactorGate('https://vcard.samirgroup.net/')->getContent())->toBe('ok');

    // Same user, same unverified session, NOC host: still challenged. This is the
    // guarantee that makes the skip above safe — it is scoped to the host, not
    // baked into the session as a `2fa_verified` flag.
    $noc = twoFactorGate('https://noc.samirgroup.net/admin');
    expect($noc->getStatusCode())->toBe(302);
    expect($noc->headers->get('Location'))->toContain('two-factor');
});
