<?php

namespace App\Services\BranchHealth;

/**
 * Reads branch health tuning, surviving a stale config cache.
 *
 * This deployment runs `php artisan config:cache`. A cache built before
 * config/branch_health.php existed does not contain it, and Laravel does not
 * merge missing files back in when the config is cached -- so on the first
 * request after a deploy every config('branch_health.*') lookup returns null.
 *
 * That took the board down with "Trying to access array offset on null", which
 * Laravel promotes to an ErrorException and a 500. Falling back to the shipped
 * file keeps the numbers CORRECT rather than merely non-fatal: defaulting the
 * MOS threshold to 0 would have scored every call as passing and quietly
 * reported a healthy fleet, which is worse than an error page.
 *
 * Values set in config still win, so this only fills genuine gaps.
 */
class BranchHealthConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $shipped = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = config("branch_health.{$key}");

        if ($value !== null) {
            return $value;
        }

        return data_get(self::shipped(), $key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    /** @return array<mixed> */
    public static function array(string $key, array $default = []): array
    {
        $value = self::get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    private static function shipped(): array
    {
        if (self::$shipped !== null) {
            return self::$shipped;
        }

        $path = config_path('branch_health.php');

        // If the file is gone too there is nothing sensible to fall back on;
        // callers pass their own defaults for that case.
        return self::$shipped = is_file($path) ? (array) require $path : [];
    }

    /** Testing seam -- drops the memoized copy of the shipped defaults. */
    public static function flush(): void
    {
        self::$shipped = null;
    }
}
