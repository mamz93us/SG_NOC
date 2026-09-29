<?php

namespace App\Services\OraclePortal;

/**
 * An Oracle announcement's `description`, taken apart.
 *
 * Since Oracle's API release of 2026-09-27 the field is filled for every
 * announcement, and it is not text: it is the notice's HTML from Oracle's
 * editor, **base64-encoded**, with the designed picture inlined in it as a
 * `data:image/png;base64,…` URI — base64 inside base64, which is why the list
 * of 61 notices is ~16 MB. Measured on 2026-09-29: 57 of 61 are nothing but
 * that picture in a centred div, four carry a line or a table of text and a
 * link, two are an empty `<div><br></div>`, and one (a test notice) is HTML
 * that was never encoded at all.
 *
 * So this returns the three things the NOC can use and nothing else:
 *
 *  - **text** — what a person would read, as plain text. The HTML itself is
 *    never kept or rendered: it is Oracle's editor output, and the home
 *    portal prints `body` escaped, as it does for every notice typed here.
 *  - **images** — the inlined pictures as bytes, checked by their content
 *    rather than by the MIME type the URI claims.
 *  - **link** — the first web link, since a notice's "register here" is
 *    useless as text without its address.
 *
 * Pure: no database, no HTTP, no clock.
 */
final class AnnouncementContent
{
    /** announcements.body is TEXT: 65,535 bytes. The longest text measured is 1,287 characters. */
    public const MAX_TEXT_BYTES = 60000;

    /** Measured pictures run to ~550 KB; a notice is not a photo album. */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_IMAGES = 6;

    /** What the home portal will serve. Anything else is dropped, whatever the URI said. */
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * @return array{text: string, images: list<array{mime: string, bytes: string, sha1: string}>, link: ?string}
     */
    public static function parse(mixed $description): array
    {
        $html = self::html($description);

        return [
            'text' => self::text($html),
            'images' => self::images($html),
            'link' => self::link($html),
        ];
    }

    /**
     * The HTML the description holds: decoded when it is base64 of readable
     * text, as it is, else taken as it stands — the one notice Oracle stores
     * unencoded, and plain text should HR ever type some.
     */
    public static function html(mixed $description): string
    {
        if (! is_string($description)) {
            return '';
        }

        $raw = trim($description);

        if ($raw === '') {
            return '';
        }

        // Only a string made wholly of the base64 alphabet is a candidate, and
        // "Holiday" is one. So a decode is believed only when what comes out
        // is readable UTF-8 with no control characters, and is either HTML —
        // Oracle's editor always writes some — or long enough that no typed
        // word could pass for it.
        if (preg_match('#^[A-Za-z0-9+/\r\n]+={0,2}$#', $raw)) {
            $decoded = base64_decode($raw, true);

            if ($decoded !== false && $decoded !== ''
                && (str_contains($decoded, '<') || strlen($raw) >= 16)
                && mb_check_encoding($decoded, 'UTF-8')
                && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded)) {
                return $decoded;
            }
        }

        return $raw;
    }

    /**
     * Readable text: block ends become line breaks, table cells a gap, every
     * tag and entity resolved, blank runs collapsed. An inlined picture is
     * removed before anything else, so half a megabyte of base64 can never
     * leak into the text as an attribute left behind.
     */
    public static function text(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style|head)\b.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<img\b[^>]*>#i', '', $html) ?? '';
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? '';
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|ul|ol|blockquote)\s*>#i', "\n", $html) ?? '';
        $html = preg_replace('#</t[dh]\s*>#i', "\t", $html) ?? '';

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // \x{00A0} is the &nbsp; editors scatter everywhere.
            $line = trim(preg_replace('/[\h\x{00A0}]+/u', ' ', $line) ?? '');

            // One blank line at most between paragraphs.
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }

            $lines[] = $line;
        }

        $text = trim(implode("\n", $lines));

        return mb_strcut($text, 0, self::MAX_TEXT_BYTES, 'UTF-8');
    }

    /**
     * The inlined pictures, in page order, each at most once.
     *
     * @return list<array{mime: string, bytes: string, sha1: string}>
     */
    public static function images(string $html): array
    {
        if (! preg_match_all('#data:image/[a-z0-9.+-]+;base64,([A-Za-z0-9+/=\s]+)#i', $html, $matches)) {
            return [];
        }

        $images = [];
        $seen = [];

        foreach ($matches[1] as $encoded) {
            // Checked before decoding, so an absurd one never reaches memory
            // twice: base64 is 4 characters per 3 bytes.
            if (strlen($encoded) > self::MAX_IMAGE_BYTES * 4 / 3 + 4) {
                continue;
            }

            $bytes = base64_decode(preg_replace('/\s+/', '', $encoded) ?? '', true);

            if ($bytes === false || $bytes === '') {
                continue;
            }

            $mime = self::sniff($bytes);
            $sha1 = sha1($bytes);

            if ($mime === null || isset($seen[$sha1])) {
                continue;
            }

            $seen[$sha1] = true;
            $images[] = ['mime' => $mime, 'bytes' => $bytes, 'sha1' => $sha1];

            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $images;
    }

    /** The first http(s) link, or null. Nothing else — no mailto:, no javascript:. */
    public static function link(string $html): ?string
    {
        // The pictures go first: a base64 run can contain anything.
        $html = preg_replace('#data:[^"\'\s>]*#i', '', $html) ?? '';

        if (! preg_match('#<a\b[^>]*\bhref\s*=\s*(["\'])\s*(https?://[^"\']+?)\s*\1#i', $html, $m)) {
            return null;
        }

        $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // announcements.link_url is varchar(500).
        return filter_var($url, FILTER_VALIDATE_URL) && strlen($url) <= 500 ? $url : null;
    }

    /** The image type by its first bytes, never by what the data URI claimed. */
    private static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }
}
