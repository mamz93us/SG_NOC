<?php

namespace App\Http\Middleware;

use App\Support\VCard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the digital business card subdomain card-ONLY.
 *
 * The card host and NOC are the same Laravel app, so every NOC route would
 * otherwise answer here too — routes registered without Route::domain() match any
 * host. This closes that: on the card host we serve the card pages, the SSO
 * sign-in, and nothing else. The admin area, the portal hub, the public
 * phonebook — all 404.
 *
 * That containment is what makes skipping 2FA on this host defensible (see
 * RequireTwoFactor): a session established here can only ever reach a business
 * card. It is never marked `2fa_verified`, so the moment that same user hits a
 * NOC route the normal 2FA gate still challenges them.
 */
class EnforceVcardHostIsolation
{
    /**
     * Named routes allowed on the card host, in addition to everything under the
     * `vcard.*` namespace (the portal itself + login/logout).
     */
    private const ALLOWED_NAMES = [
        // The shared card routes. These are registered host-agnostically so they
        // keep answering on NOC for links already printed and sent.
        'employee.card.show',
        'employee.card.vcard',
        'employee.card.wallet',
        // Microsoft SSO. No 2FA routes: this host skips 2FA outright.
        'auth.microsoft',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! VCard::enabled() || ! VCard::isHost($request)) {
            return $next($request);
        }

        if (! $this->allowedOnCardHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function allowedOnCardHost(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (str_starts_with($name, 'vcard.')) {
            return true;
        }

        if (in_array($name, self::ALLOWED_NAMES, true)) {
            return true;
        }

        // Unnamed routes still needed here: the SSO callback and the health probe.
        return $request->is('auth/microsoft/callback') || $request->is('up');
    }
}
