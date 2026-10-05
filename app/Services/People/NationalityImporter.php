<?php

namespace App\Services\People;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Services\OraclePortal\PortalBook;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Oracle's nationality export onto employee records.
 *
 * The sheet is two columns, PERSON_NUMBER and SYSTEM_NATIONALITY, one row per
 * person Oracle has ever held (1,489 on 2026-10-05, against 617 in the API's
 * current list — it includes people who have left).
 *
 * **A person number is matched inside the Oracle book's branches only**, the
 * same people the employee sync writes to and for the same reason: the SSS
 * Egypt and SamirGroup number series collide, so number 512 in this sheet and
 * the Cairo employee holding 512 are two different people. A number held by
 * two records inside the book is not guessed between either — both are left
 * alone and counted.
 *
 * It writes what the sheet says and nothing else: somebody the sheet does not
 * list keeps what they hold, and a row with no nationality changes nothing. So
 * a partial export, or the wrong file, can add and correct but never erase.
 */
class NationalityImporter
{
    /** Header spellings accepted for each column, compared in upper case without punctuation. */
    private const HEADERS = [
        'number' => ['PERSONNUMBER', 'EMPNO', 'EMPLOYEENUMBER'],
        'nationality' => ['SYSTEMNATIONALITY', 'NATIONALITY'],
    ];

    public function __construct(private PortalBook $book) {}

    /**
     * @return array{sheet_rows:int, people:int, blank:int, conflicting:int, matched:int, set:int,
     *               unchanged:int, not_in_noc:int, ambiguous:int, nationalities:array<string,int>, dry_run:bool}
     */
    public function importFile(string $path, bool $dryRun = false, ?int $userId = null): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

        return $this->import(self::read($rows), $dryRun, $userId, basename($path));
    }

    /**
     * The sheet's rows as person number => nationality.
     *
     * Pure. A number listed twice with two different nationalities is dropped
     * and counted rather than settled by whichever row came last.
     *
     * @param  array<int, array<int, mixed>>  $rows  as PhpSpreadsheet's toArray() gives them
     * @return array{people: array<string,string>, sheet_rows:int, blank:int, conflicting:int}
     *
     * @throws RuntimeException when the two columns cannot be found
     */
    public static function read(array $rows): array
    {
        $columns = null;
        $headerAt = null;

        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            $found = [];

            foreach ($row as $col => $cell) {
                $name = preg_replace('/[^A-Z]/', '', mb_strtoupper((string) $cell));

                foreach (self::HEADERS as $key => $spellings) {
                    if (in_array($name, $spellings, true)) {
                        $found[$key] ??= $col;
                    }
                }
            }

            if (count($found) === count(self::HEADERS)) {
                [$columns, $headerAt] = [$found, $i];
                break;
            }
        }

        if ($columns === null) {
            throw new RuntimeException('This is not the nationality export: no PERSON_NUMBER and SYSTEM_NATIONALITY columns were found.');
        }

        $people = [];
        $conflicts = [];
        $blank = 0;
        $count = 0;

        foreach ($rows as $i => $row) {
            if ($i <= $headerAt) {
                continue;
            }

            $number = self::number($row[$columns['number']] ?? null);

            if ($number === '') {
                continue;
            }

            $count++;
            $nationality = trim((string) preg_replace('/\s+/u', ' ', (string) ($row[$columns['nationality']] ?? '')));

            if ($nationality === '' || $nationality === '-') {
                $blank++;

                continue;
            }

            $nationality = mb_substr($nationality, 0, 100);

            if (isset($people[$number]) && $people[$number] !== $nationality) {
                $conflicts[$number] = true;
            }

            $people[$number] = $nationality;
        }

        return [
            'people' => array_diff_key($people, $conflicts),
            'sheet_rows' => $count,
            'blank' => $blank,
            'conflicting' => count($conflicts),
        ];
    }

    /**
     * @param  array{people: array<string,string>, sheet_rows:int, blank:int, conflicting:int}  $sheet
     * @return array{sheet_rows:int, people:int, blank:int, conflicting:int, matched:int, set:int,
     *               unchanged:int, not_in_noc:int, ambiguous:int, nationalities:array<string,int>, dry_run:bool}
     */
    public function import(array $sheet, bool $dryRun = false, ?int $userId = null, ?string $filename = null): array
    {
        $people = $sheet['people'];
        $branchIds = $this->book->branchIds();

        // The same people the employee sync writes to: main records holding an
        // Oracle number in the book's branches, or not yet given a branch.
        $byNumber = Employee::query()
            ->whereNotNull('oracle_emp_no')
            ->where('oracle_emp_no', '<>', '')
            ->whereNull('linked_primary_employee_id')
            ->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'))
            ->get(['id', 'oracle_emp_no', 'oracle_nationality'])
            ->groupBy(fn (Employee $e) => self::number($e->oracle_emp_no));

        $result = [
            'sheet_rows' => $sheet['sheet_rows'], 'people' => count($people), 'blank' => $sheet['blank'],
            'conflicting' => $sheet['conflicting'], 'matched' => 0, 'set' => 0, 'unchanged' => 0,
            'not_in_noc' => 0, 'ambiguous' => 0, 'nationalities' => [], 'dry_run' => $dryRun,
        ];
        $changes = [];

        foreach ($people as $number => $nationality) {
            $holders = $byNumber->get((string) $number);

            if (! $holders) {
                $result['not_in_noc']++;

                continue;
            }

            if ($holders->count() > 1) {
                $result['ambiguous']++;

                continue;
            }

            $employee = $holders->first();
            $result['matched']++;
            $result['nationalities'][$nationality] = ($result['nationalities'][$nationality] ?? 0) + 1;

            if ($employee->oracle_nationality === $nationality) {
                $result['unchanged']++;

                continue;
            }

            $changes[] = [$employee, $nationality];
            $result['set']++;
        }

        arsort($result['nationalities']);

        if ($dryRun || $changes === []) {
            return $result;
        }

        DB::transaction(function () use ($changes, $result, $userId, $filename) {
            // One row for the import, not one per person.
            Auditor::withoutAuditing(function () use ($changes) {
                foreach ($changes as [$employee, $nationality]) {
                    $employee->forceFill(['oracle_nationality' => $nationality])->save();
                }
            });

            ActivityLog::create([
                'model_type' => Employee::class,
                'model_id' => 0,
                'model_label' => 'Nationality import',
                'action' => 'employee_nationalities_imported',
                'changes' => ['file' => $filename] + array_diff_key($result, ['nationalities' => true, 'dry_run' => true]),
                'user_id' => $userId,
            ]);
        });

        return $result;
    }

    /** A person number as both sides hold it: digits as text, leading zeros dropped, "1001.0" read as 1001. */
    private static function number(mixed $value): string
    {
        $text = trim((string) $value);

        if (preg_match('/^(\d+)\.0+$/', $text, $m)) {
            $text = $m[1];
        }

        return ltrim($text, '0');
    }
}
