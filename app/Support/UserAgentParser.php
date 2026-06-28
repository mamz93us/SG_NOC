<?php

namespace App\Support;

/**
 * Minimal, dependency-free User-Agent parser. Covers the browsers / OSes /
 * device types seen on the corporate network. Deliberately small — if richer
 * detection is ever needed, swap this for a package behind the same shape.
 *
 * parse() always returns ['browser' => ?string, 'platform' => ?string,
 * 'device_type' => 'desktop'|'mobile'|'tablet'|'bot'|'unknown'].
 */
class UserAgentParser
{
    /** @return array{browser: ?string, platform: ?string, device_type: string} */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return ['browser' => null, 'platform' => null, 'device_type' => 'unknown'];
        }

        return [
            'browser' => self::browser($ua),
            'platform' => self::platform($ua),
            'device_type' => self::deviceType($ua),
        ];
    }

    public static function isBot(?string $ua, array $needles): bool
    {
        $ua = strtolower(trim((string) $ua));

        if ($ua === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($ua, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge/Opera/Chrome all carry "Chrome"/"Safari" tokens.
        return match (true) {
            (bool) preg_match('/Edg(?:e|A|iOS)?\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/i', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/i', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            (bool) preg_match('/MSIE |Trident\//i', $ua) => 'Internet Explorer',
            default => 'Other',
        };
    }

    private static function platform(string $ua): ?string
    {
        return match (true) {
            (bool) preg_match('/Windows NT/i', $ua) => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/CrOS/i', $ua) => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Other',
        };
    }

    private static function deviceType(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }
}
