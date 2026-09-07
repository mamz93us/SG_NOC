<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the employee home portal's own language choice.
 *
 * English is the default; a visitor who has switched to Arabic is remembered
 * by a plain cookie (not the session) — the silent-SSO guest flow and the
 * post-login redirect both start with no session, and a cookie is the only
 * thing that survives across those hops. This ONLY affects the home portal
 * host: it is registered on that route group alone, never on the app-wide
 * `web` group, so it can never bleed the admin/NOC locale.
 */
class SetHomePortalLocale
{
    public const COOKIE = 'home_locale';

    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
