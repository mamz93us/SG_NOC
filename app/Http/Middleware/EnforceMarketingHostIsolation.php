<?php

namespace App\Http\Middleware;

use App\Support\Marketing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the marketing subdomain marketing-ONLY.
 *
 * The marketing host and NOC are served by the same Laravel app (same code/DB,
 * domain-routed). The marketing portal is pinned to the marketing host via
 * Route::domain(); this middleware closes the other half: on the marketing host
 * we serve ONLY the marketing portal, the shared sign-in + 2FA flow, and the
 * recipient-facing email endpoints. Everything else — the NOC portal hub, the
 * admin area, the public phonebook — 404s; it has no business on em.
 *
 * This is an allow-list on purpose: the entire marketing portal lives under the
 * `portal.marketing.*` route namespace, so new portal routes are covered
 * automatically, while anything NOC stays invisible here. Defense-in-depth —
 * those routes are permission-gated anyway, but a marketing user should never
 * even see that NOC exists on their subdomain.
 */
class EnforceMarketingHostIsolation
{
    /**
     * Named routes allowed on the marketing host in addition to everything under
     * the `portal.marketing.*` namespace (the portal itself + login/logout).
     */
    private const ALLOWED_NAMES = [
        // Microsoft SSO + the mandatory 2FA enrolment / challenge flow.
        'auth.microsoft',
        'two-factor.challenge',
        'two-factor.verify',
        'admin.two-factor.setup',
        'admin.two-factor.confirm',
        'admin.two-factor.disable',
        'logout',
        // Recipient-facing email endpoints (these also answer on NOC for links
        // already delivered against the old host).
        'email.unsubscribe.show',
        'email.unsubscribe.confirm',
        'email.opt-in.confirm',
        'email.template.preview',
        'certificates.show',
        'certificates.download',
        'api.sns.email-events',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() !== Marketing::domain()) {
            return $next($request);
        }

        if (! $this->allowedOnMarketingHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function allowedOnMarketingHost(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        // The entire marketing portal is namespaced portal.marketing.*
        if (str_starts_with($name, 'portal.marketing.')) {
            return true;
        }

        if (in_array($name, self::ALLOWED_NAMES, true)) {
            return true;
        }

        // Unnamed routes still needed on em: the SSO callback and the health probe.
        return $request->is('auth/microsoft/callback') || $request->is('up');
    }
}
