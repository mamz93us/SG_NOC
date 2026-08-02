<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the HR portal subdomain.
 *
 * Mirrors App\Support\VCard: the host lives in config (config/hr_portal.php,
 * env HR_PORTAL_DOMAIN) rather than the settings table, so it is already
 * loaded before routes are registered and there is no bootstrapping hazard.
 */
class HrPortal
{
    /**
     * The configured HR host (no scheme, no path), e.g. "hr.samirgroup.net".
     */
    public static function domain(): string
    {
        $domain = trim((string) config('hr_portal.domain', ''));

        return $domain !== '' ? $domain : 'hr.samirgroup.net';
    }

    /**
     * Whether the HR subdomain is switched on.
     */
    public static function enabled(): bool
    {
        return (bool) config('hr_portal.enabled', true);
    }

    /**
     * True when this request arrived on the HR host.
     */
    public static function isHost(Request $request): bool
    {
        return $request->getHost() === self::domain();
    }

    /**
     * Absolute https URL on the HR host. Defaults to the root.
     */
    public static function url(string $path = '/'): string
    {
        return 'https://'.self::domain().'/'.ltrim($path, '/');
    }
}
