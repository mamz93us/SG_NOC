<?php

namespace App\Services\Ai;

/**
 * Article numbers the way a law writes them and the way employees ask for
 * them, turned into one key ("77", "79-bis") that can be compared.
 *
 * The imported labor law heads its articles in Arabic words ("المادة السابعة
 * والسبعون بعد المائة") and in digits in English ("Article 177"); employees
 * type "المادة 77", "الماده السابعه و السبعون" or "Article 77". Embeddings score
 * any article number as close as any other, so a search for Article 77 came
 * back with Articles 73, 105 and 118.
 *
 * Pure. Arabic ordinal words up to 399, digits in Arabic or Latin script, and
 * "مكرر" / "bis".
 */
class ArticleReference
{
    private const UNITS = [
        'اولي' => 1, 'اول' => 1, 'حاديه' => 1, 'حادي' => 1,
        'ثانيه' => 2, 'ثاني' => 2, 'ثالثه' => 3, 'ثالث' => 3,
        'رابعه' => 4, 'رابع' => 4, 'خامسه' => 5, 'خامس' => 5,
        'سادسه' => 6, 'سادس' => 6, 'سابعه' => 7, 'سابع' => 7,
        'ثامنه' => 8, 'ثامن' => 8, 'تاسعه' => 9, 'تاسع' => 9,
        'عاشره' => 10, 'عاشر' => 10,
    ];

    private const TENS = [
        'عشرون' => 20, 'عشرين' => 20, 'ثلاثون' => 30, 'ثلاثين' => 30,
        'اربعون' => 40, 'اربعين' => 40, 'خمسون' => 50, 'خمسين' => 50,
        'ستون' => 60, 'ستين' => 60, 'سبعون' => 70, 'سبعين' => 70,
        'ثمانون' => 80, 'ثمانين' => 80, 'تسعون' => 90, 'تسعين' => 90,
    ];

    private const HUNDREDS = [
        'مائه' => 100, 'مئه' => 100,
        'مائتين' => 200, 'مائتان' => 200, 'مئتين' => 200, 'مئتان' => 200,
        'ثلاثمائه' => 300, 'ثلاثمئه' => 300,
    ];

    /** "عشرة" after a unit: الحادية عشرة is 11. */
    private const TEEN = ['عشره', 'عشر'];

    /** The word for article, alone or with a preposition attached, as normalize() leaves it. */
    private const KEYWORDS = ['الماده', 'ماده', 'للماده', 'بالماده', 'والماده', 'فالماده', 'article', 'art'];

    private const BIS = ['مكرر', 'bis', 'repeated'];

    /** The article a question or search names, or null when it names none. */
    public static function inQuery(string $query): ?string
    {
        $tokens = self::tokens($query);

        foreach ($tokens as $i => $token) {
            if (! in_array($token, self::KEYWORDS, true)) {
                continue;
            }

            $at = in_array($tokens[$i + 1] ?? '', ['رقم', 'no', 'number'], true) ? $i + 2 : $i + 1;

            if (preg_match('/^\d{1,3}$/', $tokens[$at] ?? '')) {
                return self::key((int) $tokens[$at], in_array($tokens[$at + 1] ?? '', self::BIS, true));
            }

            $number = self::number($tokens, $at, $next);

            if ($number !== null) {
                return self::key($number, in_array($tokens[$next] ?? '', self::BIS, true));
            }
        }

        // Nothing but the number in words, as in "السابعة و السبعون".
        $number = self::number($tokens, 0, $next);
        $bis = in_array($tokens[$next] ?? '', self::BIS, true);

        return $number !== null && $next + ($bis ? 1 : 0) === count($tokens) ? self::key($number, $bis) : null;
    }

    /** The article a heading is — "المادة السابعة والسبعون:", "Article 77: Compensation" — or null. */
    public static function ofHeading(?string $heading): ?string
    {
        return self::label(explode(':', trim((string) $heading), 2)[0]);
    }

    /** Whether a line is an article's label and nothing else, as an unmarked heading in imported text is. */
    public static function isLabel(string $line): bool
    {
        $line = rtrim(trim(trim($line), '*_'), ': ');

        return $line !== '' && mb_strlen($line) <= 80 && ! str_contains($line, ':') && self::label($line) !== null;
    }

    private static function label(string $text): ?string
    {
        $tokens = self::tokens($text);

        if (! in_array($tokens[0] ?? '', ['الماده', 'ماده', 'article'], true)) {
            return null;
        }

        if (preg_match('/^\d{1,3}$/', $tokens[1] ?? '')) {
            $bis = in_array($tokens[2] ?? '', self::BIS, true);

            return count($tokens) === 2 + ($bis ? 1 : 0) ? self::key((int) $tokens[1], $bis) : null;
        }

        $number = self::number($tokens, 1, $next);
        $bis = in_array($tokens[$next] ?? '', self::BIS, true);

        return $number !== null && $next + ($bis ? 1 : 0) === count($tokens) ? self::key($number, $bis) : null;
    }

    /**
     * The number spelled by the words from $from on — "السابعه والسبعون بعد
     * المائه" is 177 — with $next set to the first word that is not part of it.
     *
     * @param  array<int, string>  $tokens
     */
    private static function number(array $tokens, int $from, ?int &$next = null): ?int
    {
        $total = 0;
        $seen = false;

        for ($i = $from; $i < count($tokens); $i++) {
            if ($seen && $tokens[$i] === 'و') {
                continue;
            }

            $word = self::bare($tokens[$i]);

            if (isset(self::UNITS[$word])) {
                $total += self::UNITS[$word];
            } elseif (isset(self::TENS[$word])) {
                $total += self::TENS[$word];
            } elseif (isset(self::HUNDREDS[$word])) {
                $total += self::HUNDREDS[$word];
            } elseif ($seen && in_array($word, self::TEEN, true)) {
                $total += 10;
            } elseif ($seen && $word === 'بعد') {
                continue;
            } else {
                break;
            }

            $seen = true;
        }

        $next = $i;

        return $seen ? $total : null;
    }

    /** A number word without the "و" (and) and "ال" (the) in front of it. */
    private static function bare(string $word): string
    {
        if (str_starts_with($word, 'و') && mb_strlen($word) > 2) {
            $rest = self::withoutArticle(mb_substr($word, 1));

            if (isset(self::UNITS[$rest]) || isset(self::TENS[$rest]) || isset(self::HUNDREDS[$rest]) || in_array($rest, self::TEEN, true)) {
                return $rest;
            }
        }

        return self::withoutArticle($word);
    }

    private static function withoutArticle(string $word): string
    {
        return str_starts_with($word, 'ال') && mb_strlen($word) > 3 ? mb_substr($word, 2) : $word;
    }

    /**
     * Words in one spelling: digits in Latin script, no harakat or tatweel, one
     * alef, ى as ي and ة as ه — how "الماده السابعه" is typed as often as
     * "المادة السابعة" — and English in lower case.
     *
     * @return array<int, string>
     */
    private static function tokens(string $text): array
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
        ]);
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return preg_split('/[^\p{L}\p{Nd}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function key(int $number, bool $bis): ?string
    {
        return $number > 0 ? $number.($bis ? '-bis' : '') : null;
    }
}
