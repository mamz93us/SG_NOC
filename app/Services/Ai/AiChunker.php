<?php

namespace App\Services\Ai;

/**
 * Splits a markdown article into heading-sized retrieval chunks.
 *
 * Pure and dependency-free on purpose — this is the piece that has to behave
 * the same on a long Arabic policy document as on a short English FAQ, so it
 * is unit-tested directly rather than only indirectly through the indexer.
 */
class AiChunker
{
    /** Chunks longer than this (in characters) are split further at paragraph breaks. */
    public const MAX_CHARS = 1500;

    /**
     * @return array<int, array{heading: ?string, content: string}>
     */
    public static function chunk(string $markdown): array
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return [];
        }

        $sections = self::splitByHeadings($markdown);

        $chunks = [];
        foreach ($sections as $section) {
            foreach (self::splitLongSection($section['content']) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $chunks[] = ['heading' => $section['heading'], 'content' => $piece];
                }
            }
        }

        return $chunks;
    }

    /**
     * @return array<int, array{heading: ?string, content: string}>
     */
    private static function splitByHeadings(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];

        $sections = [];
        $heading = null;
        $buffer = [];

        $flush = function () use (&$sections, &$heading, &$buffer) {
            $content = trim(implode("\n", $buffer));
            if ($content !== '') {
                $sections[] = ['heading' => $heading, 'content' => $content];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*$/u', $line, $m)) {
                $flush();
                $heading = trim($m[2]);

                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        // No headings at all — the whole document is one section.
        if ($sections === [] && trim($markdown) !== '') {
            $sections[] = ['heading' => null, 'content' => trim($markdown)];
        }

        return $sections;
    }

    /**
     * A section under the char limit is returned whole; a longer one is cut at
     * paragraph breaks, then (if a single paragraph is still too long) at
     * sentence-ish breaks, so no single chunk blows the embedding/context budget.
     *
     * @return array<int, string>
     */
    private static function splitLongSection(string $content): array
    {
        if (mb_strlen($content) <= self::MAX_CHARS) {
            return [$content];
        }

        $paragraphs = preg_split('/\R{2,}/u', $content) ?: [$content];

        $pieces = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            if (mb_strlen($para) > self::MAX_CHARS) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach (self::splitLongParagraph($para) as $sub) {
                    $pieces[] = $sub;
                }

                continue;
            }

            $candidate = $current === '' ? $para : $current."\n\n".$para;
            if (mb_strlen($candidate) > self::MAX_CHARS) {
                $pieces[] = $current;
                $current = $para;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** @return array<int, string> */
    private static function splitLongParagraph(string $para): array
    {
        // Split on sentence-ending punctuation common to both English and
        // Arabic text (Arabic full stop / question mark included), falling
        // back to a hard cut when a "sentence" is still too long on its own.
        $sentences = preg_split('/(?<=[.!?\x{061F}\x{06D4}])\s+/u', $para) ?: [$para];

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current.' '.$sentence;

            if (mb_strlen($candidate) > self::MAX_CHARS) {
                if ($current !== '') {
                    $pieces[] = $current;
                }
                $current = mb_strlen($sentence) > self::MAX_CHARS
                    ? mb_substr($sentence, 0, self::MAX_CHARS)
                    : $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
