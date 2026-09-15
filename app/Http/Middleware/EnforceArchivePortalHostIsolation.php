<?php

namespace App\Http\Middleware;

use App\Support\ArchivePortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the document archive subdomain ARCHIVE-ONLY.
 *
 * The archive host and NOC are the same Laravel app, so every NOC route would
 * otherwise answer here too — routes registered without Route::domain() match
 * any host. This closes that: on the archive host we serve the archive portal,
 * the SSO sign-in, and nothing else. The admin area, the NOC dashboards, the
 * public phonebook, the remote browser — all 404.
 *
 * That containment is what makes skipping 2FA on this host defensible (see
 * RequireTwoFactor), and it is only half of the story. The other half is that
 * this host holds real financial and HR scans, so containment alone is NOT the
 * security boundary: every document, file, stream, download and AI tool
 * re-checks per-archive membership on every request through
 * Services\Archive\ArchiveAccess, and a document outside the person's archives
 * 404s rather than 403s. The session is never marked `2fa_verified`, so the
 * moment that same user hits a NOC route the normal 2FA gate challenges them.
 */
class EnforceArchivePortalHostIsolation
{
    /**
     * Named routes allowed on the archive host, in addition to everything under
     * the `archive.*` namespace (the portal itself + login/logout).
     */
    private const ALLOWED_NAMES = [
        // Microsoft SSO. No 2FA routes: this host skips 2FA outright.
        'auth.microsoft',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! ArchivePortal::enabled() || ! ArchivePortal::isHost($request)) {
            return $next($request);
        }

        if (! $this->allowedOnArchiveHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function allowedOnArchiveHost(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (str_starts_with($name, 'archive.')) {
            return true;
        }

        if (in_array($name, self::ALLOWED_NAMES, true)) {
            return true;
        }

        // Unnamed routes still needed here: the SSO callback and the health probe.
        return $request->is('auth/microsoft/callback') || $request->is('up');
    }
}
