<?php

namespace App\Services\OraclePortal;

use Carbon\CarbonImmutable;

/**
 * One row of Oracle's /attendance view turned into the shape the HR import
 * already stages.
 *
 * /attendance rather than /employees: it is the same 617 people from the same
 * Oracle view with more columns — personId, assignmentId, gender, personType
 * and assignmentStatusType — and personId is the only key Oracle's per-person
 * endpoints accept.
 *
 * Pure: no database, no HTTP, no clock it is not handed. It is where the two
 * date traps live.
 *
 * **Hire dates are not leave dates.** Oracle writes both as dd-MMM-yy, but
 * hire years in this feed run from 1988 to 2026 and 21 people were hired
 * before 2000. VacationImporter::date() bounds years to 2000-2100, which is
 * right for leave — nobody books annual leave in 1995 — and would silently
 * reject a fifth of the workforce here. Hence a separate parser with a wider
 * window.
 *
 * **A two-digit year needs the right pivot.** PHP reads `y` as 2000-2069 for
 * 00-69 and 1970-1999 for 70-99, which happens to be exactly right across
 * 1988-2026: `88` is 1988 and `26` is 2026.
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
     * Oracle's category values, as the live feed uses them. Kept for display
     * and validation only — this is a job category, and must never be confused
     * with Employee::TYPE_SERVICE, which means "holds no mailbox".
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'SALES', 'SERVICE', 'OPERATION', 'ADMINISTRATORS',
        'ENGINEER', 'COLLECTOR', 'MARKETING', 'RETAIL',
    ];

    /**
     * @param  list<array<string,mixed>>  $rows  an /attendance payload
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
            // Oracle's /attendance carries no mobile number and no DEPT NO, so
            // this feed cannot replace the spreadsheet import — it sits beside
            // it. writeToEmployee() only ever writes a non-empty value, so an
            // API batch never blanks a mobile the sheet set. Do not "tidy" that
            // rule away.
            'mobile_no' => '',
            'dept_no' => '',
            'location_name' => self::text($row['locationName'] ?? null),
            'dept_name' => self::text($row['orgName'] ?? null),
            'job_name' => self::text($row['jobName'] ?? null),
            'gender' => self::gender($row['gender'] ?? null),

            // Only the API carries these.
            'person_id' => self::text($row['personId'] ?? null),
            'assignment_id' => self::text($row['assignmentId'] ?? null),
            'person_name_ar' => self::text($row['personNameAr'] ?? null),
            'employee_category' => self::text($row['employeeCategory'] ?? null),
            'assignment_status' => mb_strtoupper(self::text($row['assignmentStatusType'] ?? null) ?? '') ?: null,
            'person_type' => self::text($row['personType'] ?? null),
            'supervisor_name' => self::text($row['supervisorName'] ?? null),
            'manager_name' => self::text($row['managerName'] ?? null),
            'hire_date' => self::hireDate($row['startDate'] ?? null)?->toDateString(),
        ];
    }

    /**
     * Is Oracle saying this person's assignment has ended?
     *
     * Only an explicit INACTIVE counts. Absence from the feed means nothing:
     * the feed is the Saudi book, so every SSS Egypt employee is permanently
     * absent from it, along with anyone Oracle has not onboarded yet.
     */
    public static function isInactive(?string $assignmentStatus): bool
    {
        return mb_strtoupper(trim((string) $assignmentStatus)) === 'INACTIVE';
    }

    /**
     * Oracle's dd-MMM-yy hire date.
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

        try {
            // ucfirst(strtolower()) so Oracle's OCT matches PHP's Oct — the
            // same trick VacationImporter uses on the same date format.
            $parsed = CarbonImmutable::createFromFormat('!d-M-y', ucfirst(mb_strtolower($text)));
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed) {
            return null;
        }

        // Carbon rolls an impossible day over rather than refusing it, so
        // "31-FEB-20" comes back as 2 March. A hire date nobody can explain is
        // worse than none, so compare the day back against the input.
        if (preg_match('/^\s*(\d{1,2})/', $text, $m) && (int) $m[1] !== $parsed->day) {
            return null;
        }

        if ($parsed->year < self::MIN_HIRE_YEAR || $parsed->gt($now->addYear())) {
            return null;
        }

        return $parsed;
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
