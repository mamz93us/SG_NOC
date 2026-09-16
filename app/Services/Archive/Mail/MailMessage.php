<?php

namespace App\Services\Archive\Mail;

/**
 * A raw RFC 5322 message, read far enough to get the scans out of it.
 *
 * Written by hand rather than with a library because there is no library to use:
 * `zbateson/mail-mime-parser` is not installed and cannot be added without
 * network access, `symfony/mime` (which Laravel vendors) can only BUILD messages
 * and has no reader, and the `mailparse` extension is not on this PHP. The choice
 * is a small reader or no scan-to-email at all.
 *
 * Deliberately narrow. This is not a mail client: it answers "who was this sent
 * to" and "what files are attached", and nothing else. No HTML rendering, no
 * inline-image handling, no character-set conversion of bodies — a copier sends a
 * plain multipart/mixed with one or more PDFs or TIFFs, and that is the whole
 * shape it has to cope with.
 *
 * Pure and static-friendly for the same reason PageReader::parse() is: the spool
 * directory and Postfix cannot be exercised offline, but every way a message can
 * be malformed can.
 */
class MailMessage
{
    /** Attachment names a copier produces are trusted for nothing but display. */
    private const MAX_NAME = 255;

    /**
     * @param  array<string,array<int,string>>  $headers  lower-cased name => values
     * @param  array<int,array{name:string, mime:string, bytes:string}>  $attachments
     */
    private function __construct(
        private array $headers,
        private array $attachments,
    ) {}

    /** Read a raw message. Never throws: a message that makes no sense has no attachments. */
    public static function parse(string $raw): self
    {
        // Normalise line endings first. A copier may send bare LF, Postfix stores
        // CRLF, and a spool file edited by hand can hold either — every boundary
        // and blank-line test below would otherwise be subtly wrong.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        [$headerBlock, $body] = self::split($raw);
        $headers = self::readHeaders($headerBlock);

        return new self($headers, self::readParts($headers, $body));
    }

    // ─── Where it was sent ───────────────────────────────────────

    /**
     * Every address this was addressed to, lower-cased.
     *
     * To, Cc and the envelope recipient Postfix records in Delivered-To or
     * X-Original-To, because a copier configured with several destinations sends
     * one message to all of them and each is a separate inbox.
     *
     * The SENDER is deliberately not read anywhere in this class: the NOC relay
     * rewrites every sender to one SES-verified identity
     * (deployment/smtp-relay/sender_canonical.regexp is literally `/.+/ →
     * scanner@samirgroup.com`), so the From address of an arriving scan
     * identifies nothing at all. Routing is by recipient, always.
     *
     * @return array<int,string>
     */
    public function recipients(): array
    {
        $found = [];

        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'to', 'cc'] as $header) {
            foreach ($this->headers[$header] ?? [] as $value) {
                foreach (self::addressesIn($value) as $address) {
                    $found[$address] = true;
                }
            }
        }

        return array_keys($found);
    }

    public function subject(): string
    {
        return trim(self::decodeHeader($this->header('subject') ?? ''));
    }

    /** What the copier claimed to be, for the record. Never used for routing. */
    public function from(): string
    {
        return trim(self::decodeHeader($this->header('from') ?? ''));
    }

    public function header(string $name): ?string
    {
        return ($this->headers[strtolower($name)] ?? [])[0] ?? null;
    }

    // ─── What it carried ─────────────────────────────────────────

    /**
     * The attachments, decoded.
     *
     * @return array<int,array{name:string, mime:string, bytes:string}>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * Attachments that look like a scan, by extension.
     *
     * Extension rather than the declared MIME type, because copiers lie about
     * the latter routinely — a Ricoh sends PDFs as application/octet-stream — and
     * the archive probe found the extensions honest across every real file.
     *
     * @param  array<int,string>  $extensions
     * @return array<int,array{name:string, mime:string, bytes:string}>
     */
    public function scans(array $extensions): array
    {
        return array_values(array_filter(
            $this->attachments,
            fn (array $part) => in_array(
                strtolower(pathinfo($part['name'], PATHINFO_EXTENSION)),
                $extensions,
                true,
            ),
        ));
    }

    // ─── Reading ─────────────────────────────────────────────────

    /** @return array{0:string, 1:string} */
    private static function split(string $raw): array
    {
        $break = strpos($raw, "\n\n");

        return $break === false
            ? [$raw, '']
            : [substr($raw, 0, $break), substr($raw, $break + 2)];
    }

    /**
     * Headers, unfolded, as lower-cased name => list of values.
     *
     * A header can legally continue on the next line if it starts with
     * whitespace, and a long Content-Type with a boundary almost always does —
     * miss that and every multipart message reads as having no boundary.
     *
     * @return array<string,array<int,string>>
     */
    private static function readHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        $value = '';

        foreach (explode("\n", $block) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $value .= ' '.trim($line);

                continue;
            }

            if ($name !== null) {
                $headers[$name][] = trim($value);
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                $name = null;
                $value = '';

                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = substr($line, $colon + 1);
        }

        if ($name !== null) {
            $headers[$name][] = trim($value);
        }

        return $headers;
    }

    /**
     * Walk the message and collect anything that is a file.
     *
     * @param  array<string,array<int,string>>  $headers
     * @return array<int,array{name:string, mime:string, bytes:string}>
     */
    private static function readParts(array $headers, string $body): array
    {
        $contentType = ($headers['content-type'] ?? [])[0] ?? 'text/plain';
        $boundary = self::parameter($contentType, 'boundary');

        // Not multipart: the whole body is one part, and it is a scan only if it
        // announces itself as a file (a copier that mails a single PDF with no
        // MIME wrapper at all does exist).
        if ($boundary === null) {
            $part = self::readPart(implode("\n", self::headerLines($headers))."\n\n".$body);

            return $part === null ? [] : [$part];
        }

        $found = [];

        foreach (self::sections($body, $boundary) as $section) {
            $sectionHeaders = self::readHeaders(self::split($section)[0]);
            $nestedType = ($sectionHeaders['content-type'] ?? [])[0] ?? '';

            // multipart/mixed inside multipart/alternative is normal; the scan can
            // be at any depth, so recurse rather than assuming one level.
            if (self::parameter($nestedType, 'boundary') !== null) {
                $found = array_merge($found, self::readParts($sectionHeaders, self::split($section)[1]));

                continue;
            }

            $part = self::readPart($section);

            if ($part !== null) {
                $found[] = $part;
            }
        }

        return $found;
    }

    /**
     * One MIME section as a file, or null when it is not one.
     *
     * @return array{name:string, mime:string, bytes:string}|null
     */
    private static function readPart(string $section): ?array
    {
        [$headerBlock, $body] = self::split($section);
        $headers = self::readHeaders($headerBlock);

        $type = ($headers['content-type'] ?? [])[0] ?? '';
        $disposition = ($headers['content-disposition'] ?? [])[0] ?? '';

        $name = self::parameter($disposition, 'filename') ?? self::parameter($type, 'name');

        if ($name === null) {
            // No filename anywhere: the message body, not an attachment.
            return null;
        }

        $encoding = strtolower(trim(($headers['content-transfer-encoding'] ?? [])[0] ?? '7bit'));

        $bytes = match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => (string) quoted_printable_decode($body),
            default => $body,
        };

        if ($bytes === '') {
            return null;
        }

        return [
            'name' => self::safeName(self::decodeHeader($name)),
            'mime' => trim(strtok($type, ';') ?: 'application/octet-stream'),
            'bytes' => $bytes,
        ];
    }

    /**
     * The body split on a boundary.
     *
     * @return array<int,string>
     */
    private static function sections(string $body, string $boundary): array
    {
        $parts = explode('--'.$boundary, $body);

        // The first piece is the preamble and the last is whatever follows the
        // closing --boundary--; neither is a part.
        array_shift($parts);

        $sections = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break; // the closing boundary
            }

            $sections[] = ltrim($part, "\n");
        }

        return $sections;
    }

    /** A `name="value"` parameter from a header, quoted or bare. */
    private static function parameter(string $header, string $name): ?string
    {
        // RFC 2231 continuations (name*0=, name*=) exist but no copier in this
        // fleet sends them; a plain quoted or bare value covers every real case.
        if (preg_match('/;\s*'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $header, $m)) {
            return $m[1];
        }

        if (preg_match('/;\s*'.preg_quote($name, '/').'\s*=\s*([^;\s]+)/i', $header, $m)) {
            return trim($m[1], '"\'');
        }

        return null;
    }

    /**
     * Addresses out of a header value.
     *
     * @return array<int,string>
     */
    private static function addressesIn(string $value): array
    {
        preg_match_all('/[\w.!#$%&\'*+\/=?^`{|}~-]+@[\w-]+(?:\.[\w-]+)+/', $value, $matches);

        return array_map('strtolower', $matches[0] ?? []);
    }

    /**
     * An RFC 2047 encoded-word header, as plain text.
     *
     * Scanners name files after the machine and the date, but an Arabic Windows
     * driver will send `=?UTF-8?B?…?=` and the filename is what a person looks
     * for in their inbox.
     */
    private static function decodeHeader(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    /** @param array<string,array<int,string>> $headers @return array<int,string> */
    private static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }

        return $lines;
    }

    /**
     * A filename safe to store and to show.
     *
     * A copier's filename reaches a blob path and a web page, and this one came
     * off the network: anything that could traverse a directory or carry markup
     * is replaced rather than escaped.
     */
    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'scan';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name === '' || $name === '.' || $name === '..'
            ? 'scan'
            : mb_substr($name, 0, self::MAX_NAME);
    }
}
