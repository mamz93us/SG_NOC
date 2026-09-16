<?php

namespace App\Services\Archive\Ai;

/**
 * Takes archive document links back out of an assistant answer.
 *
 * The chat renders replies as plain text, so a URL the model writes arrives as a
 * URL: unclickable, and long enough to bury the sentence it sits in. What people
 * want is a button.
 *
 * So the link is removed here and the document is handed to the widget as data,
 * which draws View and Download for it. The prompt already tells the model not to
 * write URLs; this is the part that does not depend on it obeying.
 *
 * Pure and static, because the failure modes are all in the text: markdown with a
 * stray space before the bracket (which is what production produced), a bare URL
 * at the end of a sentence, several links in one answer, the same document twice.
 */
class ArchiveLinks
{
    /** Anything longer is not a document id. */
    private const MAX_ID_DIGITS = 12;

    /**
     * Strip the links, keeping the sentence readable.
     *
     * A markdown link keeps its LABEL — "you can view it [here](url)" has to read
     * as "you can view it here", not "you can view it ." — while a bare URL goes
     * entirely, because nothing in the sentence depended on it.
     *
     * @return array{0:string, 1:array<int,int>} the cleaned text, and the document
     *                                           ids found, in the order they appeared
     */
    public static function strip(string $text, string $host): array
    {
        $ids = [];
        $host = preg_quote(rtrim($host, '/'), '#');
        $url = '(?:https?://)?'.$host.'/documents/(\d{1,'.self::MAX_ID_DIGITS.'})(?!\d)(?:/[^\s)\]]*)?';

        // [label](url) and [label] (url) — the space is not valid markdown, and is
        // exactly what the model produced in production.
        $text = preg_replace_callback(
            '#\[([^\]]*)\]\s*\(\s*'.$url.'\s*\)#i',
            function (array $m) use (&$ids) {
                $ids[] = (int) $m[2];

                return $m[1];
            },
            $text
        ) ?? $text;

        // A bare URL, with any trailing punctuation left where it was.
        $text = preg_replace_callback(
            '#<?\b'.$url.'>?#i',
            function (array $m) use (&$ids) {
                $ids[] = (int) $m[1];

                return '';
            },
            $text
        ) ?? $text;

        return [self::tidy($text), array_values(array_unique($ids))];
    }

    /**
     * Close the gaps a removed URL leaves behind.
     *
     * Removing a link out of the middle of a sentence leaves doubled spaces, a
     * space before the full stop, and sometimes an empty pair of brackets.
     */
    private static function tidy(string $text): string
    {
        $text = preg_replace('/\(\s*\)|\[\s*\]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([.,;:!?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]+$/um', '', $text) ?? $text;

        return trim($text);
    }
}
