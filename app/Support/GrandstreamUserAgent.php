<?php

namespace App\Support;

/**
 * Grandstream phones identify themselves in the User-Agent on every HTTP
 * request they make to us — the phonebook fetch, and each firmware/ringtone
 * pull from the firmware server:
 *
 *   Grandstream Model HW GRP2616 SW 1.0.13.59 DevId ec74d7800474
 *   Grandstream Model HW GRP2601W SW 1.0.7.32 DevId EC:74:D7:89:1A:76
 *
 * That one string is the only identity a firmware download carries, so both
 * readers parse it here rather than keeping two copies of the same regexes.
 */
class GrandstreamUserAgent
{
    /**
     * @return array{model: ?string, mac: ?string, firmware: ?string}
     */
    public static function parse(?string $userAgent): array
    {
        $userAgent = (string) $userAgent;

        $model = null;
        $mac = null;
        $firmware = null;

        if (preg_match('/Model HW\s+([A-Z0-9\-]+)/i', $userAgent, $m)) {
            $model = strtoupper($m[1]);            // GRP2616 / GRP2601W
        }

        // The running firmware. \b matters: "GRP2601W SW 1.0.7.32" must match the
        // standalone SW, not the W that ends the model name.
        if (preg_match('/\bSW\s+([0-9]+(?:\.[0-9]+)+)/i', $userAgent, $m)) {
            $firmware = $m[1];                     // 1.0.13.59
        }

        // DevId comes bare (ec74d7800474) or colon-separated; normalise to bare
        // lower-case hex, which is how devices.mac_address stores phone MACs.
        if (preg_match('/DevId\s+([0-9a-fA-F:\-]+)/i', $userAgent, $m)) {
            $hex = strtolower(preg_replace('/[^a-f0-9]/i', '', $m[1]) ?? '');
            $mac = strlen($hex) === 12 ? $hex : null;
        }

        return ['model' => $model, 'mac' => $mac, 'firmware' => $firmware];
    }

    /** Is this request from a Grandstream device at all? */
    public static function looksLikePhone(?string $userAgent): bool
    {
        return stripos((string) $userAgent, 'grandstream') !== false;
    }
}
