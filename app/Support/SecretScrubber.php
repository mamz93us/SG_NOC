<?php

namespace App\Support;

/**
 * Strips credential-shaped text out of messages that are about to be stored or
 * displayed.
 *
 * Device and API errors habitually echo the request back, which for this estate
 * means UCM usernames and MD5 challenge responses, SNMP communities, and SIP
 * passwords. Those must not reach an operator-visible payload.
 *
 * Applied on BOTH write and read: SyncUcmExtensionsJob scrubs before storing, and
 * the branch health evaluator scrubs again before surfacing. The second pass is
 * not redundant — rows written before the first pass existed, or by some other
 * path, would otherwise flow straight through to the dashboard.
 */
class SecretScrubber
{
    /**
     * Keys whose value is always sensitive, in `key=value` or `key: value` form.
     */
    private const SENSITIVE_KEYS = 'pass(word)?|pwd|token|secret|cookie|challenge|md5'
        .'|user(name)?|api_?key|community|auth|credential|bearer|session';

    public static function scrub(?string $message, int $maxLength = 255): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $clean = preg_replace(
            '/\b('.self::SENSITIVE_KEYS.')\b\s*[=:]\s*\S+/i',
            '$1=[redacted]',
            $message
        );

        // Basic-auth credentials embedded in a URL (https://user:pass@host).
        $clean = preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $clean);

        return mb_substr(trim($clean), 0, $maxLength);
    }
}
