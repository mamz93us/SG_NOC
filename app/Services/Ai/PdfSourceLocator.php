<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeArticle;

/**
 * Where a chunk of an imported article is in the original PDF: the page its
 * text starts on, and — when the chunk's heading is a translation — the
 * heading as the PDF writes it.
 *
 * Asked where an answer came from, the assistant cited "Article 198" of the
 * imported labor law: a correct translation of a heading the PDF spells out
 * in words ("المادة الثامنة والتسعون بعد المائة"), so nothing an employee could
 * find in it. The page and the original heading are.
 *
 * Built from the import's own pages, joined the way PdfKnowledgeImporter
 * assembles the article, so it answers for what the PDF says: a chunk whose
 * text an admin has since rewritten simply has no page. Pure apart from
 * forArticle().
 */
class PdfSourceLocator
{
    /** @var array<string, array{text: string, starts: array<int, array{offset: int, page: int}>}> */
    private array $joined = [];

    /**
     * @param  array<int, array{language?: string, original?: string, english?: string}>  $pages  the import's pages; index 0 is page 1
     */
    public function __construct(private array $pages) {}

    public static function forArticle(AiKnowledgeArticle $article): ?self
    {
        $pages = $article->import?->pages;

        return is_array($pages) && $pages !== [] ? new self($pages) : null;
    }

    /**
     * @return array{page: ?int, heading: ?string}
     */
    public function locate(string $locale, ?string $heading, string $content): array
    {
        $field = $locale === 'ar' ? 'original' : 'english';
        $joined = $this->joined($field);

        $headingAt = $this->headingOffset($joined['text'], $heading);
        $contentAt = $this->contentOffset($joined['text'], $content, $headingAt ?? 0);

        if ($contentAt === null) {
            return ['page' => null, 'heading' => null];
        }

        return [
            'page' => $this->pageAt($joined['starts'], $contentAt),
            'heading' => $field === 'english' && $headingAt !== null
                ? $this->originalHeading($this->pageAt($joined['starts'], $headingAt), trim((string) $heading))
                : null,
        ];
    }

    /** The page texts joined as PdfKnowledgeImporter::assemble() joins them, with the byte offset each page starts at. */
    private function joined(string $field): array
    {
        if (! isset($this->joined[$field])) {
            $text = '';
            $starts = [];

            foreach ($this->pages as $index => $page) {
                if (($page['original'] ?? '') === '') {
                    continue; // a blank page is not in the article
                }

                if ($text !== '') {
                    $text .= "\n\n";
                }

                $starts[] = ['offset' => strlen($text), 'page' => $index + 1];
                $text .= (string) ($page[$field] ?? '');
            }

            $this->joined[$field] = ['text' => $text, 'starts' => $starts];
        }

        return $this->joined[$field];
    }

    private function headingOffset(string $text, ?string $heading): ?int
    {
        $heading = trim((string) $heading);

        // A Markdown heading, or a plain label line the chunker takes for one.
        if ($heading === '' || ! preg_match('/^(?:#{1,6}[ \t]+|[*_]{0,2})'.preg_quote($heading, '/').'[*_]{0,2}[ \t]*$/mu', $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return $match[0][1];
    }

    /**
     * Where the chunk's text begins: searched from its heading first, since a
     * law repeats its opening phrases and the chunk under "Article 198" is the
     * one after that heading; then anywhere; then with a shorter opening, in
     * case the longer one crosses a paragraph break the chunker re-spaced.
     */
    private function contentOffset(string $text, string $content, int $from): ?int
    {
        foreach ([120, 40] as $length) {
            $needle = mb_substr(trim($content), 0, $length);

            if ($needle === '') {
                return null;
            }

            $at = strpos($text, $needle, $from);

            if ($at === false && $from > 0) {
                $at = strpos($text, $needle);
            }

            if ($at !== false) {
                return $at;
            }
        }

        return null;
    }

    /** @param  array<int, array{offset: int, page: int}>  $starts */
    private function pageAt(array $starts, int $offset): ?int
    {
        $page = null;

        foreach ($starts as $start) {
            if ($start['offset'] > $offset) {
                break;
            }

            $page = $start['page'];
        }

        return $page;
    }

    /** The heading in the same place among the original page's headings, when its English and original have as many. */
    private function originalHeading(?int $page, string $heading): ?string
    {
        $source = $page !== null ? ($this->pages[$page - 1] ?? null) : null;

        if ($source === null || ($source['language'] ?? '') === 'en') {
            return null;
        }

        $english = self::headings((string) ($source['english'] ?? ''));
        $original = self::headings((string) ($source['original'] ?? ''));
        $index = array_search($heading, $english, true);

        if ($index === false || count($english) !== count($original) || $original[$index] === $heading) {
            return null;
        }

        return mb_substr($original[$index], 0, 255);
    }

    /** @return array<int, string> the page's headings as the chunker reads them */
    private static function headings(string $markdown): array
    {
        return array_values(array_filter(
            array_map(fn (string $line) => AiChunker::headingOf($line), preg_split('/\R/u', $markdown) ?: []),
            fn (?string $heading) => $heading !== null,
        ));
    }
}
