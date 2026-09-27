<?php

namespace App\Services\OraclePortal;

/**
 * Oracle's leave JSON turned into the rows {@see \App\Services\Vacation\VacationImporter}
 * already accepts.
 *
 * This is deliberately nothing but a key rename. Every judgement about the
 * values — the dd-MMM-yy dates, the figure bounds, a person number arriving as
 * "916.0", the sign of `absences` — belongs to the importer, which the
 * spreadsheet path goes through too. Two sources that parse their own values
 * are two sources that can disagree about the same person.
 *
 * Three details of the feed, verified against production on 2026-09-20:
 *
 *  - **`absences` is negative** (-2, -8, …) in all 525 non-zero rows. It is
 *    passed on unchanged: the importer calls negated() on it and stores a
 *    positive `used`. "Fixing" the sign here would zero everyone's used days.
 *  - **Null fields are omitted**, not sent as null, so carryover is present on
 *    536 of 602 rows. `?? null` throughout, and the importer reads a missing
 *    figure as null rather than nought.
 *  - **The balance feed carries no personId**, and neither does /employees —
 *    only /attendance does, for all 617 people. So that is where the id map
 *    comes from, and it is what fills vacation_employees.oracle_person_id, a
 *    column the spreadsheet path could only fill for the people both of its
 *    sheets named. Worth being exact about: /employees looks like the cheaper
 *    source at 213 KB against 381 KB, but it would silently produce an empty
 *    map.
 */
class VacationRows
{
    /**
     * @param  list<array<string,mixed>>  $rows  from /vacationBalance
     * @param  array<string,string>  $personIds  personNumber => personId, from /employees
     * @return list<array<string,mixed>>
     */
    public static function balances(array $rows, array $personIds = []): array
    {
        $out = [];

        foreach ($rows as $i => $row) {
            $number = self::text($row['personNumber'] ?? null);

            $out[] = [
                'person_number' => $number,
                'person_id' => $number !== null ? ($personIds[$number] ?? null) : null,
                'carryover' => $row['carryover'] ?? null,
                'accrued' => $row['accruals'] ?? null,
                'absences' => $row['absences'] ?? null,
                'balance' => $row['totalBalance'] ?? null,
                'row' => 'balance '.($i + 1),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $rows  from /vacationDetails
     * @return list<array<string,mixed>>
     */
    public static function absences(array $rows): array
    {
        $out = [];

        foreach ($rows as $i => $row) {
            $out[] = [
                'person_number' => self::text($row['personNumber'] ?? null),
                'type' => $row['absenceType'] ?? null,
                'start' => $row['startDate'] ?? null,
                'end' => $row['endDate'] ?? null,
                // No `duration`: Oracle sends none here, and the importer
                // counts work days from the dates instead.
                'row' => 'leave '.($i + 1),
            ];
        }

        return $out;
    }

    /**
     * personNumber => personId, from an /employees or /attendance payload.
     *
     * Only /attendance carries personId; /employees does not. Callers pass
     * whichever they fetched, and a payload without the column simply yields
     * an empty map rather than failing — the person ids are an enrichment, not
     * something a leave import depends on.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,string>
     */
    public static function personIds(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $number = self::text($row['personNumber'] ?? null);
            $id = self::text($row['personId'] ?? null);

            if ($number !== null && $id !== null) {
                $map[$number] = $id;
            }
        }

        return $map;
    }

    /** Trimmed, or null for anything blank — never an empty string. */
    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
