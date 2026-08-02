<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the digital business card subdomain.
 *
 * Mirrors App\Support\Marketing, but the host lives in config (config/vcard.php,
 * env VCARD_DOMAIN) rather than the settings table — nothing about it needs to be
 * editable at runtime, and config is already loaded before routes are registered,
 * so there is no bootstrapping hazard to work around.
 */
class VCard
{
    /**
     * The configured card host (no scheme, no path), e.g. "vcard.samirgroup.net".
     */
    public static function domain(): string
    {
        $domain = trim((string) config('vcard.domain', ''));

        return $domain !== '' ? $domain : 'vcard.samirgroup.net';
    }

    /**
     * Whether the card subdomain is switched on.
     */
    public static function enabled(): bool
    {
        return (bool) config('vcard.enabled', true);
    }

    /**
     * True when this request arrived on the card host.
     */
    public static function isHost(Request $request): bool
    {
        return $request->getHost() === self::domain();
    }

    /**
     * Absolute https URL on the card host. Defaults to the root.
     */
    public static function url(string $path = '/'): string
    {
        return 'https://'.self::domain().'/'.ltrim($path, '/');
    }

    /**
     * Canonical public URL for a card — this is what goes in QR codes, share
     * links and the Wallet pass barcode.
     *
     * Falls back to the current host when the subdomain is disabled, which keeps
     * cards working on a NOC-only install.
     */
    public static function cardUrl(string $token): string
    {
        return self::enabled()
            ? self::url("card/{$token}")
            : url("/card/{$token}");
    }
}
