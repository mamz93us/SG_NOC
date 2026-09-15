<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the document archive portal subdomain.
 *
 * Mirrors App\Support\HrPortal: the host lives in config
 * (config/archive_portal.php, env ARCHIVE_PORTAL_DOMAIN) rather than the
 * settings table, so it is already loaded before routes are registered and
 * there is no bootstrapping hazard.
 */
class ArchivePortal
{
    /**
     * The configured archive host (no scheme, no path), e.g. "archive.samirgroup.net".
     */
    public static function domain(): string
    {
        $domain = trim((string) config('archive_portal.domain', ''));

        return $domain !== '' ? $domain : 'archive.samirgroup.net';
    }

    /**
     * Whether the archive subdomain is switched on.
     */
    public static function enabled(): bool
    {
        return (bool) config('archive_portal.enabled', true);
    }

    /**
     * True when this request arrived on the archive host.
     */
    public static function isHost(Request $request): bool
    {
        return $request->getHost() === self::domain();
    }

    /**
     * Absolute https URL on the archive host. Defaults to the root.
     */
    public static function url(string $path = '/'): string
    {
        return 'https://'.self::domain().'/'.ltrim($path, '/');
    }
}
