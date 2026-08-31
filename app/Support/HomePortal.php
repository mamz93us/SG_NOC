<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the employee home portal subdomain.
 *
 * Mirrors App\Support\HrPortal and App\Support\VCard: the host lives in config
 * (config/home_portal.php, env HOME_PORTAL_DOMAIN) rather than the settings
 * table, so it is already loaded before routes are registered and there is no
 * bootstrapping hazard.
 */
class HomePortal
{
    /**
     * Name of the cookie that suppresses silent-SSO retries.
     *
     * Its presence means "Entra already told us this browser cannot sign in
     * without interaction". Read by HomeController before deciding to redirect.
     */
    public const SILENT_OFF_COOKIE = 'home_sso_silent_off';

    /**
     * The configured home host (no scheme, no path), e.g. "home.samirgroup.net".
     */
    public static function domain(): string
    {
        $domain = trim((string) config('home_portal.domain', ''));

        return $domain !== '' ? $domain : 'home.samirgroup.net';
    }

    /**
     * Whether the home subdomain is switched on.
     */
    public static function enabled(): bool
    {
        return (bool) config('home_portal.enabled', true);
    }

    /**
     * True when this request arrived on the home host.
     */
    public static function isHost(Request $request): bool
    {
        return $request->getHost() === self::domain();
    }

    /**
     * Absolute https URL on the home host. Defaults to the root.
     */
    public static function url(string $path = '/'): string
    {
        return 'https://'.self::domain().'/'.ltrim($path, '/');
    }

    /**
     * Minutes to suppress silent-SSO retries after Entra declines one.
     *
     * Clamped: zero would reinstate the redirect loop the cookie exists to
     * prevent, and a very long value strands someone who has since signed into
     * Windows on the sign-in button for the rest of the day.
     */
    public static function silentRetryMinutes(): int
    {
        return max(1, min(1440, (int) config('home_portal.silent_retry_minutes', 10)));
    }
}
