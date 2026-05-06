<?php
/**
 * Reads /etc/sg-noc-branch.env once per request and serves values via
 * Env::get('KEY'). Trims surrounding quotes that envsubst-style files
 * sometimes have around values.
 */

declare(strict_types=1);

class Env
{
    private static ?array $cache = null;
    private const FILE = '/etc/sg-noc-branch.env';

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = self::load();
        }
        return self::$cache[$key] ?? $default;
    }

    private static function load(): array
    {
        $out = [];
        if (!is_readable(self::FILE)) {
            error_log('[Env] cannot read ' . self::FILE);
            return $out;
        }
        foreach (file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strlen($line) === 0 || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v, "\"' \t");
        }
        return $out;
    }
}
