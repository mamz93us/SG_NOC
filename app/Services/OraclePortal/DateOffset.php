<?php

namespace App\Services\OraclePortal;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Oracle's employee and leave dates arrive a day early, and this is how the
 * NOC tells — every run, from the data, rather than trusting a setting.
 *
 * Oracle's API release of 2026-09-27 writes every hire date and every leave
 * date one day before the day it means: all 610 hire dates it shares with the
 * retired /attendance view came back exactly a day earlier, and so did 578 of
 * the leave records matched against the last import. It is the signature of
 * Riyadh midnight being written out in UTC (21:00 the day before). The
 * announcement dates are not affected.
 *
 * Leave carries its own proof. Nobody's leave starts on the Saudi weekend:
 * of 7,667 records the old view sent, 0.5% started on a Friday or Saturday.
 * Read as the new API sends them, 41% do; moved a day later, 0.4% do. So the
 * offset is whichever of {0, +1} puts leave starts off the weekend — and when
 * Oracle fixes its side, the same test picks 0 by itself, instead of every
 * date quietly moving a day late.
 *
 * It refuses rather than guesses: too few records to judge, no candidate that
 * clears the weekend, or two that both do, and the sync stops with the reason.
 * A leave record a day out would be imported as a new record beside the real
 * one, with the real one withdrawn.
 *
 * Pure: no database, no HTTP, no clock.
 */
final class DateOffset
{
    /** Oracle's dates are right, or a day early. Nothing else has been seen. */
    public const CANDIDATES = [0, 1];

    /** Leave records needed to judge. ~730 arrive on a normal day. */
    public const MIN_SAMPLE = 100;

    /** The right offset leaves at most this share of leave starting on the weekend. 0.4% measured. */
    public const MAX_WEEKEND_SHARE = 0.05;

    /**
     * … and every other candidate at least this share. 41% measured for +0
     * today. Once Oracle fixes its dates, +1 will only move Thursday starts
     * onto the Friday — 18% of annual leave in the old feed — so the bar sits
     * well under that.
     */
    public const MIN_OTHER_SHARE = 0.10;

    /**
     * The days to add to Oracle's dates, judged from leave start dates.
     *
     * @param  iterable<mixed>  $startDates  yyyy-MM-dd strings; anything else is skipped
     * @param  list<int>  $weekend  Carbon day-of-week numbers
     *
     * @throws RuntimeException when the dates do not say clearly
     */
    public static function detect(iterable $startDates, array $weekend): int
    {
        $shares = self::weekendShares($startDates, $weekend);
        $sample = $shares['sample'];
        unset($shares['sample']);

        if ($sample < self::MIN_SAMPLE) {
            throw new RuntimeException("Only {$sample} leave records arrived — too few to check whether Oracle's "
                .'dates are a day out, so nothing was changed.');
        }

        asort($shares);
        $best = array_key_first($shares);
        $others = array_diff_key($shares, [$best => true]);

        if ($shares[$best] > self::MAX_WEEKEND_SHARE || min($others) < self::MIN_OTHER_SHARE) {
            $seen = collect($shares)->map(fn ($s, $o) => sprintf('%+d day: %d%%', $o, round($s * 100)))->implode(', ');

            throw new RuntimeException("Oracle's leave dates do not line up with the working week under any "
                ."offset ({$seen} starting on the weekend), so nothing was changed.");
        }

        return (int) $best;
    }

    /**
     * Each candidate's share of starts on the weekend, plus the sample size.
     *
     * @return array<int|string, float|int> offset => share, and 'sample' => count
     */
    public static function weekendShares(iterable $startDates, array $weekend): array
    {
        $weekend = array_map('intval', $weekend);
        $days = [];

        foreach ($startDates as $value) {
            if ($date = self::date($value)) {
                $days[] = $date->dayOfWeek;
            }
        }

        $shares = ['sample' => count($days)];

        foreach (self::CANDIDATES as $offset) {
            $onWeekend = count(array_filter($days, fn ($day) => in_array(($day + $offset) % 7, $weekend, true)));
            $shares[$offset] = $days === [] ? 0.0 : (float) $onWeekend / count($days);
        }

        return $shares;
    }

    /**
     * Rows with the named yyyy-MM-dd fields moved by $days. Anything that is
     * not such a date is left exactly as it was, for the importer to judge.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string,mixed>>
     */
    public static function shift(array $rows, array $fields, int $days): array
    {
        if ($days === 0) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if ($date = self::date($value)) {
                    $rows[$i][$field] = $date->addDays($days)->format('Y-m-d');
                }
            }
        }

        return $rows;
    }

    /**
     * A real yyyy-MM-dd calendar date, or null. 2026-02-31 is null — left for
     * the parsers to refuse, not rolled into March here.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
