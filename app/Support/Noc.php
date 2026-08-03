<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Canonical NOC URLs for links that travel — notifications, emails, anything
 * a person clicks later from somewhere else.
 *
 * `route()` builds absolute URLs from the CURRENT request host. That is correct
 * for redirects, but wrong for a link that gets stored or emailed: a workflow
 * raised on hr.samirgroup.net would put
 *
 *     https://hr.samirgroup.net/admin/workflows/1
 *
 * into IT's approval notification — which 404s, because
 * EnforceHrPortalHostIsolation serves nothing but the HR workspace on that host.
 * The same applies to the marketing and business-card hosts, and to queued jobs
 * where there is no request host at all.
 *
 * Noc::route() pins the root to APP_URL, so an admin link is always a NOC link
 * no matter which host or process generated it.
 *
 * Use this for any /admin link that leaves the current response. Plain route()
 * is still right for redirect()->route(...) inside an admin controller.
 */
class Noc
{
    /**
     * The canonical NOC base URL (scheme + host, no trailing slash).
     */
    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * A named route, always rooted at the NOC host.
     *
     * Restores the previous root immediately so this cannot leak into unrelated
     * URL generation later in the same request.
     */
    public static function route(string $name, mixed $parameters = []): string
    {
        $generator = URL::getFacadeRoot();

        try {
            $generator->forceRootUrl(self::url());

            return $generator->route($name, $parameters, true);
        } finally {
            // Passing null clears the override and returns to request-derived roots.
            $generator->forceRootUrl(null);
        }
    }
}
