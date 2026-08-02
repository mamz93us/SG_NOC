<?php

namespace App\Http\Middleware;

use App\Support\HrPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the HR portal subdomain HR-ONLY.
 *
 * The HR host and NOC are the same Laravel app, so every NOC route would
 * otherwise answer here too — routes registered without Route::domain() match
 * any host. This closes that: on the HR host we serve the HR workspace, the SSO
 * sign-in, and nothing else. The admin area, the NOC dashboards, the public
 * phonebook, the remote browser — all 404.
 *
 * That containment is what makes skipping 2FA on this host defensible (see
 * RequireTwoFactor): a session established here can only ever reach the HR
 * request forms, each of which is still permission-gated and writes nothing
 * directly — every HR action becomes a WorkflowRequest that IT approves. The
 * session is never marked `2fa_verified`, so the moment that same user hits a
 * NOC route the normal 2FA gate still challenges them.
 */
class EnforceHrPortalHostIsolation
{
    /**
     * Named routes allowed on the HR host, in addition to everything under the
     * `portal.hr.*` namespace (the workspace itself + login/logout).
     */
    private const ALLOWED_NAMES = [
        // Microsoft SSO. No 2FA routes: this host skips 2FA outright.
        'auth.microsoft',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! HrPortal::enabled() || ! HrPortal::isHost($request)) {
            return $next($request);
        }

        if (! $this->allowedOnHrHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function allowedOnHrHost(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (str_starts_with($name, 'portal.hr.')) {
            return true;
        }

        if (in_array($name, self::ALLOWED_NAMES, true)) {
            return true;
        }

        // Unnamed routes still needed here: the SSO callback and the health probe.
        return $request->is('auth/microsoft/callback') || $request->is('up');
    }
}
