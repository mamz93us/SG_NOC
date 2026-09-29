<?php

namespace App\Services\OraclePortal;

use Carbon\CarbonImmutable;

/**
 * One row of Oracle's /employees list turned into the shape the HR import
 * already stages.
 *
 * Until 2026-09-27 this read the undocumented /attendance view, the only one
 * then carrying personId. Oracle's documented API removed that endpoint and
 * gave /employees what it had: personId, plus the Arabic name and a phone
 * number, which the old view never filled. Three columns went with it —
 * assignmentId, employeeCategory and personType — so a row mapped here leaves
 * them null, and writeToEmployee() only writes a non-empty value, so what the
 * last /attendance pull recorded on an employee stays.
 *
 * Pure: no database, no HTTP, no clock it is not handed.
 *
 * **Hire dates are not leave dates.** Hire years in this feed run from 1988 to
 * 2026 and 21 people were hired before 2000. VacationImporter::date() bounds
 * years to 2000-2100, which is right for leave — nobody books annual leave in
 * 1995 — and would silently reject a fifth of the workforce here. Hence a
 * separate parser with a wider window.
 */
class EmployeeFacts
{
    /**
     * The earliest year that can be somebody's start date here. The oldest in
     * the live feed is 1988.
     *
     * A constant rather than a config key on purpose: this class is pure, and
     * a pure class reaching into config is not — it also makes every test of it
     * need a booted application, which is exactly how two existing tests in
     * this suite came to fail.
     */
    public const MIN_HIRE_YEAR = 1960;

    /**
     * @param  list<array<string,mixed>>  $rows  an /employees payload
     * @return list<array<string,mixed>> rows for OracleHrImportService
     */
    public static function rows(array $rows): array
    {
        $out = [];

        foreach ($rows as $i => $row) {
            $mapped = self::row($row, $i + 1);

            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null null for a row with no person number
     */
    public static function row(array $row, int $rowNumber): ?array
    {
        $empNo = self::text($row['personNumber'] ?? null);

        if ($empNo === null) {
            return null;
        }

        return [
            'row_number' => $rowNumber,
            'emp_no' => $empNo,
            'emp_name' => self::text($row['personName'] ?? null),
            'email' => mb_strtolower(self::text($row['personEmail'] ?? null) ?? ''),
            // Staged raw: normalizeMobile() is the one place a number is
            // judged, the same as for the spreadsheet.
            'mobile_no' => self::phone($row['phone'] ?? null),
            // Oracle's API has no DEPT NO. writeToEmployee() only writes a
            // non-empty value, so an API batch never blanks the one the
            // spreadsheet set.
            'dept_no' => '',
            'location_name' => self::text($row['locationName'] ?? null),
            'dept_name' => self::text($row['department'] ?? null),
            'job_name' => self::text($row['jobName'] ?? null),
            'gender' => self::gender($row['gender'] ?? null),

            // Only the API carries these.
            'person_id' => self::text($row['personId'] ?? null),
            'person_name_ar' => self::text($row['personNameAr'] ?? null),
            // "Active" / "Inactive", staged upper-case so it reads the same as
            // the ACTIVE / INACTIVE the /attendance view recorded before.
            'assignment_status' => mb_strtoupper(self::text($row['status'] ?? null) ?? '') ?: null,
            'supervisor_name' => self::text($row['supervisorName'] ?? null),
            'manager_name' => self::text($row['managerName'] ?? null),
            'hire_date' => self::hireDate($row['startDate'] ?? null)?->toDateString(),
        ];
    }

    /**
     * Is Oracle saying this person has left?
     *
     * Oracle's rule (the API document of 2026-09-27): somebody is Active until
     * their termination date arrives, then Inactive, and a month after that
     * they drop out of the list altogether. So only an explicit INACTIVE
     * counts. Absence from the feed means nothing: the feed is the Saudi book,
     * so every SSS Egypt employee is permanently absent from it, along with
     * anyone Oracle has not onboarded yet and anyone who left over a month ago.
     */
    public static function isInactive(?string $status): bool
    {
        return mb_strtoupper(trim((string) $status)) === 'INACTIVE';
    }

    /**
     * Oracle's hire date: yyyy-MM-dd since 2026-09-27, dd-MMM-yy before.
     *
     * Bounded to plausible employment rather than to the vacation importer's
     * 2000-2100, which would reject the 21 people here hired before 2000. A
     * date beyond next year is refused too: a typo putting somebody's start in
     * 2126 should not become their hire date.
     */
    public static function hireDate(mixed $value, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        if (preg_match('/^\d{4}-\d{2}-(\d{2})$/', $text, $m)) {
            $format = '!Y-m-d';
        } elseif (preg_match('/^(\d{1,2})-[A-Za-z]{3}-\d{2}$/', $text, $m)) {
            // ucfirst(strtolower()) so Oracle's OCT matches PHP's Oct — the
            // same trick VacationImporter uses on the same date format. PHP
            // reads a two-digit year as 1970-1999 for 70-99, which is exactly
            // right across the feed's 1988-2026.
            $format = '!d-M-y';
            $text = ucfirst(mb_strtolower($text));
        } else {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat($format, $text);
        } catch (\Throwable) {
            return null;
        }

        // Carbon rolls an impossible day over rather than refusing it, so
        // 2020-02-31 comes back as 2 March. A hire date nobody can explain is
        // worse than none, so compare the day back against the input.
        if (! $parsed || $parsed->day !== (int) $m[1]) {
            return null;
        }

        if ($parsed->year < self::MIN_HIRE_YEAR || $parsed->gt($now->addYear())) {
            return null;
        }

        return $parsed;
    }

    /**
     * The phone as Oracle wrote it, or '' for none.
     *
     * 25 people carry a bare "-", Oracle's placeholder for no number. Staged
     * as it stands, normalizeMobile() would report each as an unrecognised
     * format on every pull, burying the few numbers that genuinely are.
     */
    private static function phone(mixed $value): string
    {
        $text = self::text($value) ?? '';

        return preg_match('/\d/', $text) ? $text : '';
    }

    /** Oracle sends M or F; the NOC stores male/female, or nothing. */
    private static function gender(mixed $value): ?string
    {
        return match (mb_strtoupper(trim((string) $value))) {
            'M' => 'male',
            'F' => 'female',
            default => null,
        };
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
