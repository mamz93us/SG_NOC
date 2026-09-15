<?php

namespace App\Services\Recruitment;

/**
 * An applicant's expected and current salary, read in code from their answers
 * to the application questions — never estimated by the AI.
 *
 * Teamtailor has no salary field. Jobs ask in their own words, as a number or
 * as free text ("What is your expected monthly net salary? (EGP)", "What
 * salary range are you expecting for this position (in SAR)?"). A question
 * counts when it pairs salary with expected, or with current / previous /
 * last; the many that only mention salary — a payroll test, a pay-deduction
 * scenario — do not. On 2026-09-15, 115 of the first screened job's 120
 * applicants had answered an expected-salary question worded this way, and
 * 112 a current-salary one.
 */
final class SalaryAnswers
{
    /** Below this an amount is a count of years or months, not a salary. */
    private const MIN_AMOUNT = 500;

    private const MAX_AMOUNT = 10_000_000;

    /** Looked for in this order, in the answer before the question. */
    private const CURRENCIES = [
        'EGP' => '/(?i:\begp\b|\bl\.e\b)|\bLE\b|جنيه|ج\.م/u',
        'SAR' => '/(?i:\bsar\b)|\bSR\b|ريال|ر\.س/u',
        'AED' => '/(?i:\baed\b)|درهم/u',
        'USD' => '/(?i:\busd\b|dollars?)|\$|دولار/u',
    ];

    private const THOUSANDS = 'k\b|thousand|ألف|الف|آلاف|الاف';

    private const EXPECTED = '/expect|desired|(?:راتب|مرتب)\S*\s+(?:ال)?(?:متوقع|مطلوب)|تتوقع/u';

    private const CURRENT = '/\b(?:current|currently|present|previous|last|recent)\b|(?:راتب|مرتب)\S*\s+(?:ال)?(?:حالي|سابق)|(?:آخر|اخر)\s+(?:راتب|مرتب)/u';

    /**
     * The first expected and the first current salary in the answers, in the
     * order given, so the answers that matter most go first.
     *
     * @param  list<array{question: ?string, answer: string, from?: ?string}>  $pairs  `from` (where the answer was given) is passed through
     * @param  ?string  $fallbackCurrency  for a figure that names no currency when the other figure names none either
     * @return array{
     *     expected: ?array{min: int, max: int, currency: ?string, text: string, from: ?string},
     *     current: ?array{amount: int, currency: ?string, text: string, from: ?string},
     * }
     */
    public static function extract(array $pairs, ?string $fallbackCurrency = null): array
    {
        $expected = null;
        $current = null;

        foreach ($pairs as $pair) {
            $question = (string) ($pair['question'] ?? '');
            $answer = trim((string) ($pair['answer'] ?? ''));
            $kind = self::kind($question);
            $amounts = $kind === null ? [] : (self::amounts($answer) ?: self::shorthand($answer));

            // Nobody expects nothing: a bare 0 counts only as a current salary.
            if ($kind !== 'current') {
                $amounts = array_values(array_filter($amounts, fn (int $amount) => $amount > 0));
            }

            if ($amounts === []) {
                continue;
            }

            $about = [
                'currency' => self::currency($answer) ?? self::currency($question),
                'text' => mb_substr($answer, 0, 160),
                'from' => isset($pair['from']) && trim((string) $pair['from']) !== '' ? (string) $pair['from'] : null,
            ];

            if ($kind === 'both' && count($amounts) >= 2) {
                [$now, $wanted] = self::asksExpectedFirst($question) ? [$amounts[1], $amounts[0]] : [$amounts[0], $amounts[1]];
                $current ??= ['amount' => $now] + $about;
                $expected ??= ['min' => $wanted, 'max' => $wanted] + $about;

                continue;
            }

            if ($kind === 'current') {
                $current ??= ['amount' => $amounts[0]] + $about;
            } elseif ($expected === null) {
                $range = count($amounts) >= 2 && self::isRange($answer);
                $expected = [
                    'min' => $range ? min($amounts[0], $amounts[1]) : $amounts[0],
                    'max' => $range ? max($amounts[0], $amounts[1]) : $amounts[0],
                ] + $about;
            }
        }

        $fallback = $fallbackCurrency !== null && trim($fallbackCurrency) !== '' ? strtoupper(trim($fallbackCurrency)) : null;

        // A currency named with one figure holds for the other; then the job's.
        if ($expected !== null) {
            $expected['currency'] ??= $current['currency'] ?? $fallback;
        }
        if ($current !== null) {
            $current['currency'] ??= $expected['currency'] ?? $fallback;
        }

        return ['expected' => $expected, 'current' => $current];
    }

    /** 'expected', 'current', 'both', or null for a question that is not about the applicant's own pay. */
    public static function kind(string $question): ?string
    {
        $q = mb_strtolower($question);

        if (preg_match('/salar|\bpay\b|compensation|راتب|مرتب|الأجر|الاجر/u', $q) !== 1) {
            return null;
        }

        $expected = preg_match(self::EXPECTED, $q) === 1;
        $current = preg_match(self::CURRENT, $q) === 1;

        return match (true) {
            $expected && $current => 'both',
            $expected => 'expected',
            $current => 'current',
            default => null,
        };
    }

    /**
     * Salary-sized amounts in a text, in order: "12,000", "12.500", "12 000",
     * "15k", "10-12k", "15 ألف", Arabic digits.
     *
     * @return list<int>
     */
    public static function amounts(string $text): array
    {
        $text = self::digits($text);
        $thousands = self::THOUSANDS;

        // "10-12k" is ten to twelve thousand, not ten and twelve thousand.
        $text = (string) preg_replace("/(\d+(?:\.\d+)?)\s*(-|–|—|~|to|الى|إلى|حتى)\s*(\d+(?:\.\d+)?)\s*({$thousands})/iu", '$1$4 $2 $3$4', $text);

        preg_match_all("/(\d{1,3}(?:[,.\s]\d{3})+(?!\d)|\d+(?:\.\d+)?)\s*({$thousands})?/iu", $text, $matches, PREG_SET_ORDER);

        $amounts = [];

        foreach ($matches as $match) {
            $value = preg_match('/^\d{1,3}(?:[,.\s]\d{3})+$/', $match[1]) === 1
                ? (float) preg_replace('/[,.\s]/', '', $match[1])
                : (float) $match[1];

            if (($match[2] ?? '') !== '') {
                $value *= 1000;
            }

            $value = (int) round($value);

            if ($value >= self::MIN_AMOUNT && $value <= self::MAX_AMOUNT) {
                $amounts[] = $value;
            }
        }

        // A year ("since 2023") beside a real amount is not a salary.
        if (count($amounts) > 1) {
            $amounts = array_values(array_filter($amounts, fn (int $amount) => $amount < 1950 || $amount > 2035)) ?: $amounts;
        }

        return $amounts;
    }

    /**
     * A bare one- or two-digit figure is how many applicants write thousands:
     * "25" for 25,000. On 2026-09-15, 28 of the first screened job's salary
     * answers were a bare number like that. A bare 0 stays 0: no salary now.
     *
     * @return list<int>
     */
    private static function shorthand(string $answer): array
    {
        $bare = (string) preg_replace('/\s+|egp|sar|aed|usd|l\.?e|k|جنيه|ريال|درهم/iu', '', self::digits($answer));

        if (preg_match('/^\d{1,2}$/', $bare) !== 1) {
            return [];
        }

        return [(int) $bare * 1000];
    }

    public static function currency(string $text): ?string
    {
        foreach (self::CURRENCIES as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $code;
            }
        }

        return null;
    }

    /** "12,000 EGP" or "10,000–12,000 SAR" */
    public static function format(int $min, ?int $max = null, ?string $currency = null): string
    {
        $amount = number_format($min).($max !== null && $max !== $min ? '–'.number_format($max) : '');

        return $currency !== null && $currency !== '' ? "{$amount} {$currency}" : $amount;
    }

    /** A figure from extract() for people: "12,000 EGP", "10,000–12,000 SAR"; null for none. */
    public static function label(?array $figure): ?string
    {
        if (! $figure) {
            return null;
        }

        return array_key_exists('amount', $figure)
            ? self::format((int) $figure['amount'], null, $figure['currency'] ?? null)
            : self::format((int) ($figure['min'] ?? 0), (int) ($figure['max'] ?? 0), $figure['currency'] ?? null);
    }

    /** How much more than their current salary the applicant asks, in percent; null unless both are known in one currency. */
    public static function raisePercent(?array $expected, ?array $current): ?int
    {
        if (! $expected || ! $current || (int) ($current['amount'] ?? 0) <= 0 || ($expected['currency'] ?? null) !== ($current['currency'] ?? null)) {
            return null;
        }

        return (int) round(((int) $expected['min'] - (int) $current['amount']) / (int) $current['amount'] * 100);
    }

    /** For a question asking both: whether it names the expected salary before the current one. */
    private static function asksExpectedFirst(string $question): bool
    {
        $q = mb_strtolower($question);
        preg_match(self::EXPECTED, $q, $expected, PREG_OFFSET_CAPTURE);
        preg_match(self::CURRENT, $q, $current, PREG_OFFSET_CAPTURE);

        return ($expected[0][1] ?? PHP_INT_MAX) < ($current[0][1] ?? PHP_INT_MAX);
    }

    private static function isRange(string $answer): bool
    {
        return preg_match('/\d\s*(?:k|ألف|الف)?\s*(?:-|–|—|~|\bto\b|\btill\b|\buntil\b|إلى|الى|حتى)\s*\d|\bbetween\b|\brange\b|بين|من\s*\d/iu', self::digits($answer)) === 1;
    }

    private static function digits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٬' => ',', '٫' => '.',
        ]);
    }
}
