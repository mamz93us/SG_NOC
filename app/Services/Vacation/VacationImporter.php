<?php

namespace App\Services\Vacation;

use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Models\Vacation\VacationImport;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Brings Oracle's vacation data in. The sheet upload calls it today; the
 * Oracle API will call the same two methods with the same row shapes, so
 * these rules hold for both.
 *
 * Balances — one row per person — are Oracle's figures as of a date, kept per
 * year and replaced for each person in the file. Never recomputed; an older
 * export never overwrites a newer balance.
 *
 * Leave records — one row per absence. Oracle's export lists every record that
 * starts on or after some cutoff, so a record the NOC holds that starts inside
 * the file's span of start dates and is not in the file was withdrawn or
 * changed in Oracle: it is stamped removed_at, never deleted, and restored if a
 * later export lists it again. Records starting before the span are history
 * the export no longer covers, and stay as they are.
 *
 * A row that cannot be read is skipped and named in the import's notes; it
 * never stops the rest.
 */
class VacationImporter
{
    private const MIN_YEAR = 2000;

    private const MAX_YEAR = 2100;

    /** Longer than any real leave; a typo would make the day count meaningless. */
    private const MAX_RECORD_DAYS = 400;

    /** Beyond this many, problems are counted rather than listed. */
    private const MAX_NOTES = 50;

    /** decimal(10,4) */
    private const MAX_FIGURE = 999999;

    public function __construct(private VacationLinker $linker) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  person_number, person_id (optional), carryover,
     *                                                     accrued, absences (Oracle's sign: negative), balance;
     *                                                     `row` names the sheet row in notes
     */
    public function importBalances(
        string $book,
        CarbonImmutable $asOf,
        iterable $rows,
        string $source = VacationImport::SOURCE_SHEET,
        ?string $filename = null,
        ?int $userId = null,
    ): VacationImport {
        $this->assertBook($book);

        $notes = [];
        $valid = [];
        $conflicts = [];
        $read = 0;
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $read++;
            $line = $row['row'] ?? $index + 1;

            try {
                $number = $this->personNumber($row['person_number'] ?? null);
                $values = [
                    'person_id' => $this->personId($row['person_id'] ?? null),
                    'carryover' => $this->number($row['carryover'] ?? null, 'CARRYOVER'),
                    'accrued' => $this->number($row['accrued'] ?? null, 'ACCRUALS'),
                    'used' => $this->negated($this->number($row['absences'] ?? null, 'ABSENCES')),
                    'balance' => $this->number($row['balance'] ?? null, 'TOTAL_BALANCE'),
                ];
            } catch (InvalidArgumentException $e) {
                $skipped++;
                $this->note($notes, "Row {$line}: {$e->getMessage()}");

                continue;
            }

            if (isset($valid["#{$number}"])) {
                // The same person twice: identical rows are one, different ones are nobody's.
                $skipped++;
                if ($valid["#{$number}"]['values'] != $values) {
                    $conflicts["#{$number}"] = $number;
                }

                continue;
            }

            $valid["#{$number}"] = ['number' => $number, 'values' => $values];
        }

        foreach ($conflicts as $key => $number) {
            unset($valid[$key]);
            $skipped++;
            $this->note($notes, "Person {$number} appears more than once with different figures, so none of them were imported.");
        }

        return $this->transaction(function () use ($book, $asOf, $source, $filename, $userId, $valid, $read, &$skipped, &$notes) {
            $import = VacationImport::create([
                'book' => $book,
                'kind' => VacationImport::KIND_BALANCES,
                'source' => $source,
                'filename' => $filename,
                'as_of' => $asOf->toDateString(),
                'rows' => $read,
                'imported_by' => $userId,
            ]);

            $people = $this->people($book, collect($valid)->mapWithKeys(fn ($entry) => [$entry['number'] => $entry['values']['person_id']])->all());
            $held = VacationBalance::query()
                ->where('year', $asOf->year)
                ->whereIn('vacation_employee_id', collect($people)->pluck('id')->all() ?: [0])
                ->get()
                ->keyBy('vacation_employee_id');

            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $newer = 0;

            foreach ($valid as $entry) {
                $person = $people["#{$entry['number']}"];
                $figures = array_diff_key($entry['values'], ['person_id' => true]);
                $balance = $held->get($person->id);

                if ($balance && $balance->as_of && $balance->as_of->gt($asOf)) {
                    $newer++;

                    continue;
                }

                $attributes = $figures + ['as_of' => $asOf->toDateString(), 'vacation_import_id' => $import->id];

                if (! $balance) {
                    VacationBalance::create($attributes + ['vacation_employee_id' => $person->id, 'year' => $asOf->year]);
                    $counts['created']++;

                    continue;
                }

                $changed = collect($figures)->contains(fn ($value, $column) => ! $this->sameFigure($balance->{$column}, $value));
                $balance->fill($attributes)->save();
                $counts[$changed ? 'updated' : 'unchanged']++;
            }

            if ($newer > 0) {
                $skipped += $newer;
                $this->note($notes, "{$newer} people already have a balance as of a later date than {$asOf->format('d M Y')}; those were kept.");
            }

            return $this->finish($import, $counts + [
                'skipped' => $skipped,
                'unlinked' => collect($people)->whereNull('employee_id')->count(),
            ], $notes);
        });
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  person_number, type, start, end, duration (optional);
     *                                                     `row` names the sheet row in notes
     * @param  bool  $withdrawMissing  the rows are Oracle's whole list from their earliest start date on, as
     *                                 an export is; false for a feed that sends only some records
     */
    public function importAbsences(
        string $book,
        iterable $rows,
        string $source = VacationImport::SOURCE_SHEET,
        ?string $filename = null,
        ?int $userId = null,
        bool $withdrawMissing = true,
    ): VacationImport {
        $this->assertBook($book);
        $weekend = VacationEmployee::books()[$book]['weekend'] ?? [];

        $notes = [];
        $records = [];
        $read = 0;
        $skipped = 0;
        $duplicates = 0;

        foreach ($rows as $index => $row) {
            $read++;
            $line = $row['row'] ?? $index + 1;

            try {
                $number = $this->personNumber($row['person_number'] ?? null);
                $type = $this->type($row['type'] ?? null);
                $start = $this->date($row['start'] ?? null, 'VAC_START_DATE');
                $end = $this->date($row['end'] ?? null, 'VAC_END_DATE');
                $duration = $this->number($row['duration'] ?? null, 'duration');

                if ($end < $start) {
                    throw new InvalidArgumentException("it ends on {$end}, before it starts on {$start}");
                }

                $days = VacationDays::count(CarbonImmutable::parse($start), CarbonImmutable::parse($end), $weekend);

                if ($days['calendar'] > self::MAX_RECORD_DAYS) {
                    throw new InvalidArgumentException("{$start} to {$end} is {$days['calendar']} days, longer than any leave");
                }
            } catch (InvalidArgumentException $e) {
                $skipped++;
                $this->note($notes, "Row {$line}: {$e->getMessage()}");

                continue;
            }

            $key = mb_strtolower("{$number}|{$type}|{$start}|{$end}");

            if (isset($records[$key])) {
                $duplicates++;

                continue;
            }

            $records[$key] = compact('number', 'type', 'start', 'end', 'duration') + $days;
        }

        if ($duplicates > 0) {
            $skipped += $duplicates;
            $this->note($notes, "{$duplicates} rows repeated a record already in the file and were counted once.");
        }

        $starts = array_column($records, 'start');
        $from = $starts ? min($starts) : null;
        $to = $starts ? max($starts) : null;

        return $this->transaction(function () use ($book, $source, $filename, $userId, $withdrawMissing, $records, $read, $skipped, $notes, $from, $to) {
            $import = VacationImport::create([
                'book' => $book,
                'kind' => VacationImport::KIND_ABSENCES,
                'source' => $source,
                'filename' => $filename,
                'window_from' => $withdrawMissing ? $from : null,
                'window_to' => $withdrawMissing ? $to : null,
                'rows' => $read,
                'imported_by' => $userId,
            ]);

            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'restored' => 0, 'skipped' => $skipped, 'unlinked' => 0];

            if ($records === []) {
                return $this->finish($import, $counts, $notes);
            }

            $people = $this->people($book, array_fill_keys(array_unique(array_column($records, 'number')), null));

            $held = DB::table('vacation_absences')
                ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_absences.vacation_employee_id')
                ->where('vacation_employees.book', $book)
                ->whereBetween('vacation_absences.start_date', [$from, $to])
                ->get([
                    'vacation_absences.id', 'vacation_absences.vacation_employee_id', 'vacation_absences.absence_type',
                    'vacation_absences.start_date', 'vacation_absences.end_date', 'vacation_absences.calendar_days',
                    'vacation_absences.work_days', 'vacation_absences.duration', 'vacation_absences.removed_at',
                ])
                ->keyBy(fn ($row) => mb_strtolower($row->vacation_employee_id.'|'.$row->absence_type.'|'
                    .substr((string) $row->start_date, 0, 10).'|'.substr((string) $row->end_date, 0, 10)));

            $now = now();
            $seen = [];
            $inserts = [];
            $touched = [];
            $restored = [];

            foreach ($records as $record) {
                $person = $people["#{$record['number']}"];
                $existing = $held->get(mb_strtolower("{$person->id}|{$record['type']}|{$record['start']}|{$record['end']}"));

                if (! $existing) {
                    $inserts[] = [
                        'vacation_employee_id' => $person->id,
                        'absence_type' => $record['type'],
                        'start_date' => $record['start'],
                        'end_date' => $record['end'],
                        'calendar_days' => $record['calendar'],
                        'work_days' => $record['work'],
                        'duration' => $record['duration'],
                        'first_import_id' => $import->id,
                        'last_import_id' => $import->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }

                $seen[$existing->id] = true;

                $recount = (int) $existing->calendar_days !== $record['calendar']
                    || (int) $existing->work_days !== $record['work']
                    || ($record['duration'] !== null && ! $this->sameFigure($existing->duration === null ? null : (float) $existing->duration, $record['duration']));

                if ($recount) {
                    DB::table('vacation_absences')->where('id', $existing->id)->update([
                        'calendar_days' => $record['calendar'],
                        'work_days' => $record['work'],
                        'duration' => $record['duration'] ?? $existing->duration,
                        'removed_at' => null,
                        'last_import_id' => $import->id,
                        'updated_at' => $now,
                    ]);
                    $existing->removed_at !== null ? $counts['restored']++ : $counts['updated']++;
                } elseif ($existing->removed_at !== null) {
                    $restored[] = $existing->id;
                } else {
                    $touched[] = $existing->id;
                }
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('vacation_absences')->insert($chunk);
            }

            foreach (array_chunk($touched, 1000) as $ids) {
                DB::table('vacation_absences')->whereIn('id', $ids)->update(['last_import_id' => $import->id, 'updated_at' => $now]);
            }

            foreach (array_chunk($restored, 1000) as $ids) {
                DB::table('vacation_absences')->whereIn('id', $ids)->update(['removed_at' => null, 'last_import_id' => $import->id, 'updated_at' => $now]);
            }

            if ($withdrawMissing) {
                $gone = $held->filter(fn ($row) => $row->removed_at === null && ! isset($seen[$row->id]))->pluck('id')->all();

                foreach (array_chunk($gone, 1000) as $ids) {
                    DB::table('vacation_absences')->whereIn('id', $ids)->update(['removed_at' => $now, 'updated_at' => $now]);
                }

                $counts['removed'] = count($gone);
            }

            $counts['created'] = count($inserts);
            $counts['unchanged'] = count($touched);
            $counts['restored'] += count($restored);
            $counts['unlinked'] = collect($people)->whereNull('employee_id')->count();

            return $this->finish($import, $counts, $notes);
        });
    }

    /**
     * The person rows for these numbers — created when new, Oracle's person id
     * filled in when the sheet carries one — linked by the rule.
     *
     * @param  array<string|int, ?string>  $numbers  person number => Oracle person id
     * @return array<string, VacationEmployee> keyed "#<person number>"
     */
    private function people(string $book, array $numbers): array
    {
        // Array keys turn "166" into 166; the column is text and "0166" must never equal it.
        $numbers = collect($numbers)->mapWithKeys(fn ($personId, $number) => ["#{$number}" => [(string) $number, $personId]]);

        $existing = $numbers->pluck(0)->chunk(500)
            ->flatMap(fn ($chunk) => VacationEmployee::query()->where('book', $book)->whereIn('oracle_emp_no', $chunk->values()->all())->get())
            ->keyBy(fn (VacationEmployee $person) => "#{$person->oracle_emp_no}");

        $people = [];

        foreach ($numbers as $key => [$number, $personId]) {
            $person = $existing->get($key);

            if (! $person) {
                $person = VacationEmployee::create(['book' => $book, 'oracle_emp_no' => $number, 'oracle_person_id' => $personId]);
            } elseif ($personId !== null && $person->oracle_person_id !== $personId) {
                $person->forceFill(['oracle_person_id' => $personId])->save();
            }

            $people[$key] = $person;
        }

        $this->linker->linkAll($people);

        return $people;
    }

    /** @param  array<string, int>  $counts */
    private function finish(VacationImport $import, array $counts, array $notes): VacationImport
    {
        if ($missing = $this->linker->missingBranches($import->book)) {
            $this->note($notes, 'config/vacations.php lists branches no branch is called: '.implode(', ', $missing)
                .'. People in them cannot be linked to an employee automatically until it is corrected.');
        }

        $import->forceFill($counts + ['notes' => $notes ?: null])->save();

        return $import;
    }

    private function transaction(callable $work): VacationImport
    {
        // One summary row is the honest record of a re-import, not thousands of per-row ones.
        return Auditor::withoutAuditing(fn () => DB::transaction($work));
    }

    private function assertBook(string $book): void
    {
        if (! array_key_exists($book, VacationEmployee::books())) {
            throw new InvalidArgumentException("Unknown vacation book '{$book}'. Books are listed in config/vacations.php.");
        }
    }

    private function note(array &$notes, string $message): void
    {
        if (count($notes) < self::MAX_NOTES) {
            $notes[] = $message;
        } elseif (count($notes) === self::MAX_NOTES) {
            $notes[] = 'More problems were found; fix the ones above and import the file again to see the rest.';
        }
    }

    private function personNumber(mixed $value): string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = sprintf('%.0f', $value);
        }

        $number = trim((string) $value);

        if ($number === '') {
            throw new InvalidArgumentException('PERSON_NUMBER is empty');
        }
        if (mb_strlen($number) > 50) {
            throw new InvalidArgumentException("PERSON_NUMBER '{$number}' is too long");
        }

        return $number;
    }

    private function personId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = is_int($value) || is_float($value) ? sprintf('%.0f', $value) : trim((string) $value);

        if (! preg_match('/^\d{1,30}$/', $id)) {
            throw new InvalidArgumentException("PERSON_ID '{$id}' is not a number");
        }

        return $id;
    }

    private function number(mixed $value, string $column): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_numeric(trim((string) $value))) {
            throw new InvalidArgumentException("{$column} '{$value}' is not a number");
        }

        $number = (float) (is_string($value) ? trim($value) : $value);

        if (abs($number) > self::MAX_FIGURE) {
            throw new InvalidArgumentException("{$column} {$number} is out of range");
        }

        return $number;
    }

    private function negated(?float $value): ?float
    {
        return $value === null ? null : 0.0 - $value;
    }

    private function type(mixed $value): string
    {
        $type = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($type === '') {
            throw new InvalidArgumentException('ABSENCE_TYPE is empty');
        }
        if (mb_strlen($type) > 100) {
            throw new InvalidArgumentException('ABSENCE_TYPE is longer than 100 characters');
        }

        return $type;
    }

    /** Oracle's "17-AUG-26", ISO dates, d/m/Y, or an Excel serial once the sheet is re-saved. */
    private function date(mixed $value, string $column): string
    {
        $date = null;

        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)))) {
            $date = DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value));
        } else {
            $text = trim((string) $value);

            if ($text === '') {
                throw new InvalidArgumentException("{$column} is empty");
            }

            foreach (['!d-M-y', '!d-M-Y', '!Y-m-d', '!Y-m-d H:i:s', '!d/m/Y', '!Y/m/d'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, ucfirst(strtolower($text)));

                if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                    $date = $parsed;
                    break;
                }
            }
        }

        if (! $date || (int) $date->format('Y') < self::MIN_YEAR || (int) $date->format('Y') > self::MAX_YEAR) {
            throw new InvalidArgumentException("{$column} '".(is_scalar($value) ? $value : '?')."' is not a date");
        }

        return $date->format('Y-m-d');
    }

    private function sameFigure(?float $held, ?float $new): bool
    {
        if ($held === null || $new === null) {
            return $held === $new;
        }

        return abs($held - $new) < 0.00005;
    }
}
