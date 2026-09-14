<?php

namespace App\Services\Ai;

/**
 * A page's footnotes, put with what they annotate.
 *
 * A law's footnotes say which articles were amended, merged or repealed, and
 * by which decree. Left at the foot of the page, nothing tied a note to its
 * article but a small raised number, and the assistant read footnote 77 —
 * which repeals Article 211 — as Article 77 repealed.
 *
 * gpt-4o would not reliably move notes under their articles, write marks in a
 * fixed syntax, or list the notes apart, and on some pages it dropped the marks
 * altogether. It does transcribe the notes at the foot of the page, word for
 * word, and a second call can say which heading each mark is printed on
 * (PdfPageTranslator). This moves each note from the foot of the page to the
 * end of that heading's section, as "[^N]: …", where the chunker keeps it with
 * that article, and takes the marks the reading left in the text back out. The
 * note's words stay the page reading's: asked to transcribe the notes itself,
 * the second call changed decree numbers and dates. A note it cannot place
 * goes under a heading of its own at the end of the page, never into the last
 * article.
 *
 * Pure.
 */
class PdfFootnotes
{
    /** A footnote as the page reading writes it: "41- …", "-76 …", "- 53: …", "[^70]: …". */
    private const NOTE_LINE = '/^\s*[-*]?\s*\[?\^?(\d{1,3})\]?\s*[-.:)]?\s*(\S.*)$/u';

    /** A heading the reading may put its notes under. */
    private const NOTES_HEADING = '/^(ال)?(ملاحظات|حواشي|هوامش)|^(notes|footnotes)\b/iu';

    /** Lines a note may run on over, below the one with its number. */
    private const CONTINUATION_LINES = 3;

    /**
     * @param  array<int, string>  $marks  footnote number => the heading its mark is printed on, "" when it is not on a heading
     * @return array{original: string, english: string}
     */
    public static function apply(string $original, string $english, array $marks): array
    {
        if ($marks === []) {
            return ['original' => $original, 'english' => $english];
        }

        $originalPage = self::read($original, $marks);
        $englishPage = self::read($english, $marks);

        foreach ($originalPage['texts'] as $number => $text) {
            self::assign($originalPage, self::find($number, $marks[$number] ?? '', $originalPage, true), "[^{$number}]: {$text}");
        }

        foreach ($englishPage['texts'] as $number => $text) {
            // English headings are translations, so only an article number or a
            // mark finds one directly; otherwise the same heading by position.
            $inOriginal = self::find($number, $marks[$number] ?? '', $originalPage, true);
            $at = self::find($number, $marks[$number] ?? '', $englishPage, false)
                ?? ($inOriginal !== null && count($englishPage['headings']) === count($originalPage['headings']) ? $inOriginal : null);

            self::assign($englishPage, $at, "[^{$number}]: {$text}");
        }

        return [
            'original' => self::write($originalPage, TextTranslator::language($original) === 'ar' ? 'الحواشي' : 'Footnotes'),
            'english' => self::write($englishPage, 'Footnotes'),
        ];
    }

    /** The page's text without the notes at its foot, the notes themselves, and where its headings and marks are. */
    private static function read(string $text, array $marks): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $texts = [];
        $pending = [];
        $last = null;
        $start = count($lines);

        // The notes at the foot, read upwards. A note is a line starting with a
        // footnote number — one the marks name, or the one before the note below
        // it — and the lines under it before the next note.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            $blank = trim($line) === '';
            $heading = $blank ? null : AiChunker::headingOf($line);

            if ($blank || ($heading !== null && preg_match(self::NOTES_HEADING, $heading))) {
                if ($pending !== []) {
                    break;
                }

                $start = $i;

                continue;
            }

            if ($heading !== null) {
                break;
            }

            $number = preg_match(self::NOTE_LINE, $line, $m) ? (int) $m[1] : null;

            if ($number !== null && ! isset($texts[$number]) && (isset($marks[$number]) || ($last !== null && $number === $last - 1))) {
                $texts[$number] = trim($m[2].' '.implode(' ', array_reverse($pending)));
                $pending = [];
                $last = $number;
                $start = $i;

                continue;
            }

            $pending[] = trim($line);

            if (count($pending) > self::CONTINUATION_LINES) {
                break;
            }
        }

        $lines = array_slice($lines, 0, $start);
        ksort($texts);

        $numbers = array_flip(array_merge(array_keys($texts), array_keys($marks)));
        $headings = [];
        $keys = [];
        $marked = [];

        foreach ($lines as $i => $line) {
            // A mark after a heading's colon: "المادة الرابعة والسبعون: 38", "…: 76.".
            if (preg_match('/^(.*:)\s*\[?\^?(\d{1,3})\]?\s*[.\-]?\s*$/u', $line, $m) && isset($numbers[(int) $m[2]]) && AiChunker::headingOf($m[1]) !== null) {
                $lines[$i] = $line = rtrim($m[1]);
                $marked[(int) $m[2]] = count($headings);
            }

            $heading = AiChunker::headingOf($line);

            if ($heading !== null) {
                $headings[] = $i;
                $keys[] = ArticleReference::ofHeading($heading);
            }
        }

        // A mark on the line under a heading: "76." on its own, or "70- (ملغاة)".
        foreach ($headings as $ordinal => $at) {
            $next = $at + 1;

            while (isset($lines[$next]) && trim($lines[$next]) === '') {
                $next++;
            }

            if (! isset($lines[$next]) || in_array($next, $headings, true)) {
                continue;
            }

            if (preg_match('/^\s*-?\s*\[?\^?(\d{1,3})\]?\s*[-.]?\s*$/u', $lines[$next], $m) && isset($numbers[(int) $m[1]])) {
                $lines[$next] = null;
                $marked[(int) $m[1]] = $ordinal;
            } elseif (preg_match('/^\s*\[?\^?(\d{1,3})\]?\s*[-.]\s+(\S.*)$/u', $lines[$next], $m) && (int) $m[1] >= 10 && isset($numbers[(int) $m[1]])) {
                // Never below 10: "1- …" under a heading is the article's first clause.
                $lines[$next] = $m[2];
                $marked[(int) $m[1]] = $ordinal;
            }
        }

        return ['lines' => $lines, 'headings' => $headings, 'keys' => $keys, 'marked' => $marked, 'texts' => $texts, 'placed' => [], 'unplaced' => []];
    }

    /**
     * The section a note belongs to, as its heading's position among the page's
     * headings, or null when it cannot be told for certain: a note put under
     * the wrong article would state that article's history wrongly, where one
     * under the page's own notes heading states nothing about any article.
     */
    private static function find(int $number, string $heading, array $page, bool $byText): ?int
    {
        // A mark the page reading wrote beside its heading is the surest sign.
        if (isset($page['marked'][$number])) {
            return $page['marked'][$number];
        }

        $ordinal = null;
        $key = $heading === '' ? null : ArticleReference::ofHeading($heading);

        if ($key !== null) {
            $found = array_search($key, $page['keys'], true);
            $ordinal = $found === false ? null : $found;
        } elseif ($byText && ($wanted = self::normalized($heading)) !== '') {
            foreach ($page['headings'] as $position => $at) {
                $candidate = self::normalized((string) AiChunker::headingOf((string) $page['lines'][$at]));

                if ($candidate !== '' && (str_starts_with($candidate, $wanted) || str_starts_with($wanted, $candidate))) {
                    $ordinal = $position;
                    break;
                }
            }
        }

        // Asked where footnote 56's mark was, the model named Article 156: it read
        // the article's name, not its mark. A heading whose number the footnote's
        // echoes is never taken on the model's word.
        if ($ordinal !== null && self::echoes($number, $page['keys'][$ordinal] ?? null)) {
            return null;
        }

        return $ordinal;
    }

    /** Whether a footnote number repeats an article number, whole or in its last digits: 56 and Article 156. */
    private static function echoes(int $number, ?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        $article = (int) $key; // "79-bis" is Article 79

        return $article === $number || ($number >= 10 ? $article % 100 === $number : $article % 10 === $number);
    }

    private static function assign(array &$page, ?int $ordinal, string $note): void
    {
        if ($ordinal === null) {
            $page['unplaced'][] = $note;

            return;
        }

        $page['placed'][$page['headings'][$ordinal + 1] ?? count($page['lines'])][] = $note;
    }

    private static function write(array $page, string $notesHeading): string
    {
        $out = [];
        $count = count($page['lines']);

        for ($i = 0; $i <= $count; $i++) {
            if (isset($page['placed'][$i])) {
                self::place($out, $page['placed'][$i]);
            }

            if ($i < $count && $page['lines'][$i] !== null) {
                $out[] = $page['lines'][$i];
            }
        }

        if ($page['unplaced'] !== []) {
            self::place($out, ["## {$notesHeading}", ...$page['unplaced']]);
        }

        return trim(implode("\n", $out));
    }

    /** @param  array<int, string>  $lines  added as a paragraph of their own */
    private static function place(array &$out, array $lines): void
    {
        while ($out !== [] && trim((string) end($out)) === '') {
            array_pop($out);
        }

        array_push($out, '', ...$lines);
        $out[] = '';
    }

    private static function normalized(string $text): string
    {
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return trim((string) preg_replace('/[^\p{L}\p{Nd}]+/u', ' ', mb_strtolower($text)));
    }
}
