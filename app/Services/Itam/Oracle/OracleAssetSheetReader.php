<?php

namespace App\Services\Itam\Oracle;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use RuntimeException;

/**
 * Reads Oracle's fixed-asset register export — the sheet HR/finance send as
 * "assets M-YYYY.xlsx", or the same saved as .xls or .csv:
 *
 *   Asset Number | Asset Name | P Date | End Date | Emp Name | Emp no
 *
 * Headers are matched loosely (case, spaces and a trailing space in "End Date "
 * do not matter). Cells come back raw — a date may be an Excel serial or text —
 * because OracleAssetImporter judges the values.
 */
class OracleAssetSheetReader
{
    /** Normalised header => row key. The first header found for a key wins. */
    private const COLUMNS = [
        'required' => [
            'ASSET_NUMBER' => 'asset_number',
            'ASSET_NAME' => 'description',
            'EMP_NO' => 'emp_no',
        ],
        'optional' => [
            'P_DATE' => 'purchase_date',
            'PURCHASE_DATE' => 'purchase_date',
            'DATE_PLACED_IN_SERVICE' => 'purchase_date',
            'END_DATE' => 'end_date',
            'EMP_NAME' => 'emp_name',
            'EMPLOYEE_NAME' => 'emp_name',
        ],
    ];

    private const ALIASES = [
        'ASSET_NO' => 'ASSET_NUMBER',
        'DESCRIPTION' => 'ASSET_NAME',
        'ASSET_DESCRIPTION' => 'ASSET_NAME',
        'EMP_NUMBER' => 'EMP_NO',
        'EMPLOYEE_NO' => 'EMP_NO',
        'EMPLOYEE_NUMBER' => 'EMP_NO',
    ];

    /** A title row or two above the header is tolerated. */
    private const HEADER_SEARCH_ROWS = 10;

    /**
     * @param  string|null  $extension  the uploaded file's extension — a temporary upload has none
     * @return list<array<string, mixed>> every row keeps its sheet row number as `row`
     */
    public function read(string $path, ?string $extension = null): array
    {
        $reader = $this->readerFor($path, $extension);
        $reader->setReadDataOnly(true);
        $cells = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);

        foreach (array_slice($cells, 0, self::HEADER_SEARCH_ROWS) as $headerIndex => $header) {
            $map = $this->columnMap(array_map(fn ($cell) => $this->headerName($cell), $header));

            if ($map !== null) {
                return $this->rows($cells, $headerIndex, $map);
            }
        }

        throw new RuntimeException('This is not Oracle\'s asset register. It needs the columns Asset Number, Asset Name '
            .'and Emp no (and normally P Date, End Date and Emp Name).');
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
        $name = trim(strtoupper((string) preg_replace('/[^0-9A-Za-z]+/', '_', $name)), '_');

        return self::ALIASES[$name] ?? $name;
    }

    /**
     * @param  list<string>  $names
     * @return array<int, string>|null column index => row key, or null when a required column is missing
     */
    private function columnMap(array $names): ?array
    {
        $map = [];

        foreach (self::COLUMNS['required'] + self::COLUMNS['optional'] as $header => $key) {
            $index = array_search($header, $names, true);

            if ($index === false) {
                if (isset(self::COLUMNS['required'][$header])) {
                    return null;
                }

                continue;
            }

            if (! in_array($key, $map, true)) {
                $map[$index] = $key;
            }
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
            $row = ['row' => $index + 1, 'purchase_date' => null, 'end_date' => null, 'emp_name' => null];
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
