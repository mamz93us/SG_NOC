<?php

namespace App\Services\Archive\ArcMate;

use Carbon\CarbonImmutable;

/**
 * ArcMate's two ways of writing a moment, both of them text.
 *
 * Its tables have no date columns at all: every timestamp is a varchar(14)
 * `yyyyMMddHHmmss` (tblDocumentsTrack.arcDate, tblFilesTrack.arcDate,
 * tblLogData.arcDate), and expiry dates are a varchar(8) `yyyyMMdd`. Stored
 * files are named the same way, which matters because tblDocuments itself keeps
 * no date — the timestamp a document's first file is named with is the only
 * record of when it was captured.
 *
 * Everything here is WALL CLOCK and stays that way. The values were written in
 * Saudi local time by a machine with no zone information anywhere, so they are
 * parsed in the app's own timezone and never converted — the same rule as
 * attendance punch times, and for the same reason: converting would shift every
 * historical document by whatever offset happened to apply.
 */
class ArcMateDates
{
    /**
     * `yyyyMMddHHmmss` (and `yyyyMMdd`) as a moment, or null.
     *
     * Null for empty, short, non-numeric and impossible values. Two decades of
     * rows written without a single constraint contain all four, and a document
     * with an unreadable date must still import — with no date — rather than
     * fail the whole batch.
     */
    public static function fromStamp(?string $value): ?CarbonImmutable
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if (strlen($digits) === 8) {
            $digits .= '000000';
        }

        if (strlen($digits) < 14) {
            return null;
        }

        return self::build(substr($digits, 0, 14));
    }

    /**
     * The moment a stored file name begins with: `20260915144709220434.pdf`
     * → 2026-09-15 14:47:09.
     *
     * The trailing characters are ArcMate's own uniqueness suffix (a few hex
     * digits shared by everything saved in one session), not part of the time.
     */
    public static function fromFileName(?string $name): ?CarbonImmutable
    {
        $base = basename(str_replace('\\', '/', (string) $name));

        if (! preg_match('/(\d{14})/', $base, $m)) {
            return null;
        }

        return self::build($m[1]);
    }

    /**
     * Turn 14 digits into a moment, rejecting anything that is not a real one.
     *
     * createFromFormat happily rolls a month of 13 into the next year, so the
     * parsed value is compared back against the digits it came from: that is
     * what tells a genuine timestamp from a field that happened to hold
     * fourteen digits.
     */
    private static function build(string $digits): ?CarbonImmutable
    {
        try {
            $moment = CarbonImmutable::createFromFormat('YmdHis', $digits);
        } catch (\Throwable) {
            return null;
        }

        if (! $moment || $moment->format('YmdHis') !== $digits) {
            return null;
        }

        // ArcMate shipped in the 2000s; anything outside a sane window is a
        // corrupt field rather than a document from the year 1200.
        if ((int) $moment->format('Y') < 1990 || (int) $moment->format('Y') > 2100) {
            return null;
        }

        return $moment;
    }
}
