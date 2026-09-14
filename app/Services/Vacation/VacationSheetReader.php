<?php

namespace App\Services\Vacation;

use App\Models\Vacation\VacationImport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use RuntimeException;

/**
 * Reads one of Oracle's vacation exports — the .xls Oracle writes, or the same
 * sheet saved as .xlsx or .csv — and tells which it is by its header row:
 *
 *   balances  PERSON_ID, PERSON_NUMBER, CARRYOVER, ACCRUALS, ABSENCES, TOTAL_BALANCE
 *   absences  PERSON_NUMBER, ABSENCE_TYPE, VAC_START_DATE, VAC_END_DATE
 *
 * Cells come back raw — a date may be Oracle's "17-AUG-26" text or an Excel
 * serial — because VacationImporter judges the values, so a sheet and the API
 * that replaces it are held to the same rules.
 */
class VacationSheetReader
{
    public const COLUMNS = [
        VacationImport::KIND_BALANCES => [
            'required' => ['PERSON_NUMBER' => 'person_number', 'CARRYOVER' => 'carryover', 'ACCRUALS' => 'accrued', 'ABSENCES' => 'absences', 'TOTAL_BALANCE' => 'balance'],
            'optional' => ['PERSON_ID' => 'person_id'],
        ],
        VacationImport::KIND_ABSENCES => [
            'required' => ['PERSON_NUMBER' => 'person_number', 'ABSENCE_TYPE' => 'type', 'VAC_START_DATE' => 'start', 'VAC_END_DATE' => 'end'],
            'optional' => [],
        ],
    ];

    /** A title row or two above the header is tolerated. */
    private const HEADER_SEARCH_ROWS = 10;

    /**
     * @param  string|null  $extension  the uploaded file's extension — a temporary upload has none
     * @return array{kind: string, rows: list<array<string, mixed>>} every row keeps its sheet row number as `row`
     */
    public function read(string $path, ?string $extension = null): array
    {
        $reader = $this->readerFor($path, $extension);
        $reader->setReadDataOnly(true);
        $cells = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);

        foreach (array_slice($cells, 0, self::HEADER_SEARCH_ROWS) as $headerIndex => $header) {
            $names = array_map(fn ($cell) => $this->headerName($cell), $header);

            foreach (self::COLUMNS as $kind => $columns) {
                if (($map = $this->columnMap($names, $columns)) !== null) {
                    return ['kind' => $kind, 'rows' => $this->rows($cells, $headerIndex, $map)];
                }
            }
        }

        throw new RuntimeException('This is not one of Oracle\'s vacation sheets. The balance sheet has the columns '
            .'PERSON_NUMBER, CARRYOVER, ACCRUALS, ABSENCES and TOTAL_BALANCE; the details sheet has '
            .'PERSON_NUMBER, ABSENCE_TYPE, VAC_START_DATE and VAC_END_DATE.');
    }

    private function readerFor(string $path, ?string $extension): IReader
    {
        return match (strtolower((string) $extension)) {
            'xls' => IOFactory::createReader('Xls'),
            'xlsx' => IOFactory::createReader('Xlsx'),
            'csv' => IOFactory::createReader('Csv'),
            default => IOFactory::createReaderForFile($path),
        };
    }

    private function headerName(mixed $cell): string
    {
        $name = str_replace("\u{FEFF}", '', trim((string) $cell));

        return strtoupper((string) preg_replace('/[\s\-]+/', '_', $name));
    }

    /**
     * @param  list<string>  $names
     * @param  array{required: array<string, string>, optional: array<string, string>}  $columns
     * @return array<int, string>|null column index => row key, or null when a required column is missing
     */
    private function columnMap(array $names, array $columns): ?array
    {
        $map = [];

        foreach ($columns['required'] + $columns['optional'] as $header => $key) {
            $index = array_search($header, $names, true);

            if ($index === false) {
                if (isset($columns['required'][$header])) {
                    return null;
                }

                continue;
            }

            $map[$index] = $key;
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, mixed>>  $cells
     * @param  array<int, string>  $map
     * @return list<array<string, mixed>>
     */
    private function rows(array $cells, int $headerIndex, array $map): array
    {
        $rows = [];

        foreach (array_slice($cells, $headerIndex + 1, null, true) as $index => $cellRow) {
            $row = ['row' => $index + 1];
            $blank = true;

            foreach ($map as $column => $key) {
                $value = $cellRow[$column] ?? null;
                $row[$key] = is_string($value) ? trim($value) : $value;
                $blank = $blank && ($row[$key] === null || $row[$key] === '');
            }

            if (! $blank) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
