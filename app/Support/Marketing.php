<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the marketing portal's subdomain.
 *
 * The host is stored in the `settings` table (column `marketing_domain`) and is
 * editable from Admin → Email Marketing → Settings — nothing lives in `.env`.
 *
 * This is read at route-registration time (Route::domain(...)), so it must be
 * bootstrapping-safe: during a fresh install, `migrate`, or `route:cache` the
 * settings table may not exist yet or the DB may be unreachable. Every failure
 * path falls back to the hard-coded default instead of throwing, which would
 * otherwise break artisan entirely.
 *
 * When an admin changes the domain, the settings controller clears the route
 * cache so a freshly-resolved value is baked into the next cached route table.
 */
class Marketing
{
    /** Fallback used until an admin configures the domain in the UI. */
    public const DEFAULT_DOMAIN = 'em.samirgroup.net';

    /** Per-process memo so we don't query settings on every helper call. */
    private static ?string $cached = null;

    /**
     * The configured marketing host (no scheme, no path), e.g. "em.samirgroup.net".
     */
    public static function domain(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $domain = self::DEFAULT_DOMAIN;

        try {
            // hasColumn guard matters: on SQLite, selecting a missing column via a
            // double-quoted identifier returns the *column name* as a string literal
            // instead of throwing, which would otherwise become a bogus host.
            if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'marketing_domain')) {
                $value = DB::table('settings')->value('marketing_domain');
                if (is_string($value) && trim($value) !== '') {
                    $domain = trim($value);
                }
            }
        } catch (\Throwable) {
            // DB unavailable (early boot / fresh install) — use the default.
        }

        return self::$cached = $domain;
    }

    /**
     * Absolute https URL on the marketing host. Defaults to the root.
     */
    public static function url(string $path = '/'): string
    {
        return 'https://'.self::domain().'/'.ltrim($path, '/');
    }

    /**
     * Reset the memo. Useful in tests after mutating the setting.
     */
    public static function flush(): void
    {
        self::$cached = null;
    }
}
