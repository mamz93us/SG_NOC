<?php

namespace App\Http\Middleware;

use App\Support\Marketing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the NOC admin surface off the marketing subdomain.
 *
 * The marketing host and NOC are served by the same Laravel app (same code /
 * DB, domain-routed). The marketing portal itself is already pinned to the
 * marketing host via Route::domain(); this middleware closes the other half:
 * the NOC admin area (/admin/*) must not be reachable on the marketing host.
 *
 * This is defense-in-depth — every /admin route is permission-gated, so a
 * marketing-role user could never DO anything there — but they have no business
 * seeing that the NOC admin even exists on their subdomain. We 404 rather than
 * redirect so the admin surface is simply invisible from the marketing host.
 */
class EnforceMarketingHostIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === Marketing::domain()
            && ($request->is('admin') || $request->is('admin/*'))) {
            abort(404);
        }

        return $next($request);
    }
}
