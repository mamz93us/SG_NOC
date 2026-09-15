<?php

namespace App\Services\Recruitment;

/**
 * A Teamtailor job ad as plain text: the title, the pitch and the description,
 * HTML removed. What a screening is judged against, and what its criteria hash
 * covers — an edited ad makes earlier screenings stale.
 */
final class JobAd
{
    /** Ads run to about 2,300 characters; this only stops a pasted brochure. */
    public const MAX_CHARS = 12000;

    public function __construct(
        public readonly string $title,
        public readonly string $text,
    ) {}

    /** @param  array<string,mixed>  $job  a JSON:API job resource */
    public static function fromTeamtailor(array $job): self
    {
        $attributes = $job['attributes'] ?? [];
        $title = trim((string) ($attributes['title'] ?? $attributes['internal-name'] ?? ''));

        $parts = array_filter([
            self::plain((string) ($attributes['pitch'] ?? '')),
            self::plain((string) ($attributes['body'] ?? '')),
        ], fn (string $part) => $part !== '');

        return new self($title, mb_substr(implode("\n\n", $parts), 0, self::MAX_CHARS));
    }

    /** HTML to readable text: block ends become line breaks, list items dashes, entities decoded. */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('/<\s*(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\s*li\b[^>]*>/i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = implode("\n", array_map('trim', preg_split('/\R/u', $text) ?: []));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** The ad as the screening reads it. */
    public function forPrompt(): string
    {
        return "Job title: {$this->title}\n\nJob ad:\n".($this->text !== '' ? $this->text : '(the ad has no description)');
    }
}
