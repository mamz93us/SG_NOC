<?php

namespace App\Http\Middleware;

use App\Support\HomePortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the employee home portal subdomain HOME-ONLY.
 *
 * The home host and NOC are the same Laravel app, so every NOC route would
 * otherwise answer here too — routes registered without Route::domain() match
 * any host. This closes that: on the home host we serve the start page, its
 * ticket endpoints, the SSO sign-in, and nothing else. The admin area, the NOC
 * dashboards, the public phonebook, the remote browser — all 404.
 *
 * That containment is what makes skipping 2FA on this host defensible (see
 * RequireTwoFactor). This is the one host in the estate that a browser opens
 * unattended on every launch, on every PC, so the surface reachable from a
 * session established here has to be small enough to describe in a sentence:
 * the person's own start page and a ticket form. The session is never marked
 * `2fa_verified`, so the moment that same user hits a NOC route the normal 2FA
 * gate still challenges them.
 */
class EnforceHomePortalHostIsolation
{
    /**
     * Named routes allowed on the home host, in addition to everything under
     * the `home.*` namespace (the page itself + its JSON endpoints).
     */
    private const ALLOWED_NAMES = [
        // Microsoft SSO. No 2FA routes: this host skips 2FA outright.
        'auth.microsoft',
        'logout',
    ];

    /**
     * Route-name prefixes allowed here in addition to `home.`.
     *
     * The staff directory is linked from the portal's Employees Directory tile,
     * so it has to answer on this host or that tile 404s. It is safe to admit:
     * `/contacts` carries no auth middleware anywhere and already answers
     * unauthenticated on every other host — allowing it here exposes nothing
     * that was not already public.
     */
    private const ALLOWED_PREFIXES = [
        'public.contacts',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! HomePortal::enabled() || ! HomePortal::isHost($request)) {
            return $next($request);
        }

        if (! $this->allowedOnHomeHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function allowedOnHomeHost(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (str_starts_with($name, 'home.')) {
            return true;
        }

        if (in_array($name, self::ALLOWED_NAMES, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        // Unnamed routes still needed here: the SSO callback and the health probe.
        return $request->is('auth/microsoft/callback') || $request->is('up');
    }
}
