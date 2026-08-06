<?php

namespace App\Support;

/**
 * Turns the app's hand-built email Blades into editable templates without
 * giving the editor the ability to run code.
 *
 * The mechanism is a pair of inert HTML comments around every value in a mail
 * view that is worth substituting:
 *
 *     <!--f:employee_name-->{{ $name }}<!--/f-->
 *
 * Because they are comments, the marked-up Blade renders exactly as it always
 * did — the markers are stripped on the way out. What they buy is three things:
 *
 *   1. extract()  — after rendering the real view with real data, pull out each
 *                   marked region as a ready-made HTML chunk.
 *   2. tokenise() — render the view with sample data and swap each marked
 *                   region for "{{ name }}", producing the starting point the
 *                   admin edits.
 *   3. fill()     — drop those chunks back into an edited body.
 *
 * So the stored template is plain HTML with {{ name }} placeholders. Filling it
 * is string substitution, never compilation: a typo in the editor produces an
 * odd-looking email, not a fatal at send time and not arbitrary PHP.
 */
class EmailTemplateRenderer
{
    /** Matches one marked region, non-greedy so adjacent fields stay separate. */
    private const REGION = '/<!--f:([a-zA-Z0-9_]+)-->(.*?)<!--\/f-->/s';

    /** Matches a {{ token }} placeholder in a stored template. */
    private const TOKEN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    /**
     * Pull every marked region out of rendered HTML.
     *
     * A field that appears more than once in a view (a name in the header and
     * again in the footer) yields the same chunk — first one wins.
     *
     * @return array<string, string> token => rendered HTML
     */
    public static function extract(string $html): array
    {
        preg_match_all(self::REGION, $html, $matches, PREG_SET_ORDER);

        $chunks = [];
        foreach ($matches as [$_, $name, $inner]) {
            if (! array_key_exists($name, $chunks)) {
                $chunks[$name] = trim($inner);
            }
        }

        return $chunks;
    }

    /**
     * Remove the markers, keeping their content. Every email goes through this
     * on the way out, whether or not a custom template exists.
     */
    public static function strip(string $html): string
    {
        return preg_replace('/<!--\/?f(?::[a-zA-Z0-9_]+)?-->/', '', $html) ?? $html;
    }

    /**
     * Replace each marked region with its {{ token }} — the seed body the admin
     * starts editing from.
     */
    public static function tokenise(string $html): string
    {
        $out = preg_replace_callback(
            self::REGION,
            fn ($m) => '{{ '.$m[1].' }}',
            $html
        ) ?? $html;

        return self::strip($out);
    }

    /**
     * Substitute chunks into a stored template.
     *
     * Chunks are inserted raw: they came out of a Blade that already escaped
     * whatever needed escaping, and some of them are deliberately HTML (a table
     * body, a list of licences). Unknown tokens resolve to nothing rather than
     * being left visible in a real email.
     *
     * @param  array<string, string>  $chunks
     */
    public static function fill(string $body, array $chunks): string
    {
        return preg_replace_callback(
            self::TOKEN,
            fn ($m) => $chunks[$m[1]] ?? '',
            $body
        ) ?? $body;
    }

    /**
     * Same, for a subject line — chunks are flattened to plain text and any
     * stray whitespace collapsed, since a subject cannot carry markup.
     *
     * @param  array<string, string>  $chunks
     */
    public static function fillText(string $text, array $chunks): string
    {
        $filled = preg_replace_callback(
            self::TOKEN,
            fn ($m) => html_entity_decode(strip_tags($chunks[$m[1]] ?? ''), ENT_QUOTES | ENT_HTML5),
            $text
        ) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $filled) ?? $filled);
    }

    /**
     * Tokens a stored template references but that the view never produces —
     * shown as a warning in the editor so a typo is caught before sending.
     *
     * @param  array<string, string>  $chunks
     * @return list<string>
     */
    public static function unknownTokens(string $body, array $chunks): array
    {
        preg_match_all(self::TOKEN, $body, $matches);

        return array_values(array_unique(array_diff($matches[1] ?? [], array_keys($chunks))));
    }

    /**
     * Field names declared by a mail view, read straight from its source. Used
     * to build the placeholder reference without having to render anything.
     *
     * @return list<string>
     */
    public static function declaredFields(string $viewPath): array
    {
        if (! is_file($viewPath)) {
            return [];
        }

        preg_match_all('/<!--f:([a-zA-Z0-9_]+)-->/', (string) file_get_contents($viewPath), $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
