<?php

namespace App\Services\Ai\Web;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * A web page as the knowledge base wants it: its title, its readable text as
 * Markdown — headings, paragraphs, lists and tables kept; menus, headers,
 * footers and scripts dropped — its language, and every link on it.
 *
 * DOM only, no rendering: a page that builds its content with JavaScript has
 * no text to give, and the crawler reports that instead of indexing a shell.
 */
class HtmlExtractor
{
    /** Never content, wherever they sit. */
    private const DROP = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed',
        'form', 'button', 'input', 'select', 'textarea', 'nav', 'header', 'footer', 'aside', 'dialog',
    ];

    /** Elements that start a new block of text. */
    private const BLOCKS = [
        'p', 'div', 'section', 'article', 'main', 'blockquote', 'figure', 'figcaption', 'address',
        'dl', 'dt', 'dd', 'ul', 'ol', 'details', 'summary', 'center',
    ];

    /** @return array{title: string, markdown: string, language: ?string, links: array<int, string>} */
    public static function extract(string $html, string $pageUrl, ?string $charset = null): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.self::toUtf8($html, $charset), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $base = trim((string) $xpath->evaluate('string(//base/@href)')) ?: $pageUrl;

        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $url = UrlTools::resolve($anchor->getAttribute('href'), $base);

            if ($url !== null) {
                $links[$url] = true;
            }
        }

        $title = self::clean((string) $xpath->evaluate('string(//meta[@property="og:title"]/@content)'))
            ?: self::clean((string) $xpath->evaluate('string(//title)'))
            ?: self::clean((string) $xpath->evaluate('string(//h1)'));

        $language = strtolower(substr(trim((string) $xpath->evaluate('string(/html/@lang)')), 0, 2)) ?: null;

        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $xpath->query('//body')->item(0);

        $markdown = '';

        if ($root) {
            $drop = './/'.implode('|.//', self::DROP)
                .'|.//*[@role="navigation" or @role="banner" or @role="contentinfo" or @aria-hidden="true"]';

            foreach (iterator_to_array($xpath->query($drop, $root) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }

            $lines = array_map(
                fn (string $line) => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
                explode("\n", self::render($root))
            );
            $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'markdown' => $markdown,
            'language' => $language,
            'links' => array_keys($links),
        ];
    }

    private static function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) preg_replace('/\s+/u', ' ', $node->textContent);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        return match (true) {
            $tag === 'h1', $tag === 'h2' => "\n\n## ".self::clean($node->textContent)."\n\n",
            in_array($tag, ['h3', 'h4', 'h5', 'h6'], true) => "\n\n### ".self::clean($node->textContent)."\n\n",
            $tag === 'br' => "\n",
            $tag === 'hr' => "\n\n",
            $tag === 'li' => "\n- ".trim(self::children($node)),
            $tag === 'tr' => "\n".self::row($node),
            $tag === 'table' => "\n\n".self::table($node)."\n\n",
            $tag === 'pre' => "\n\n".trim($node->textContent)."\n\n",
            in_array($tag, self::BLOCKS, true) => "\n\n".self::children($node)."\n\n",
            default => self::children($node),
        };
    }

    private static function children(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::render($child);
        }

        return $text;
    }

    private static function table(DOMElement $table): string
    {
        $rows = [];

        foreach ((new DOMXPath($table->ownerDocument))->query('.//tr', $table) ?: [] as $tr) {
            $row = self::row($tr);

            if ($row !== '') {
                $rows[] = $row;
            }
        }

        if (count($rows) > 1) {
            $columns = substr_count($rows[0], ' | ') + 1;
            array_splice($rows, 1, 0, ['|'.str_repeat(' --- |', $columns)]);
        }

        return implode("\n", $rows);
    }

    private static function row(DOMNode $tr): string
    {
        $cells = [];

        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $cells[] = str_replace('|', '\|', self::clean($cell->textContent));
            }
        }

        return $cells === [] || implode('', $cells) === '' ? '' : '| '.implode(' | ', $cells).' |';
    }

    private static function toUtf8(string $html, ?string $charset): string
    {
        $charset ??= preg_match('/<meta[^>]+charset=["\']?\s*([\w-]+)/i', $html, $match) ? $match[1] : null;

        if ($charset === null || in_array(strtolower($charset), ['utf-8', 'utf8'], true)) {
            return $html;
        }

        try {
            $converted = iconv($charset, 'UTF-8//IGNORE', $html);

            return is_string($converted) ? $converted : $html;
        } catch (Throwable) {
            return $html;
        }
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
