<?php

namespace App\Services\Itam\Oracle;

use App\Models\ActivityLog;
use App\Models\Itam\OracleAsset;
use App\Models\Itam\OracleAssetImport;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Brings Oracle's fixed-asset register in: the laptops and desktops, one unit
 * per row. The register is the whole company's, so every import is a full one.
 *
 *   - Software licences, monitors and other lines are left out and counted.
 *   - A unit is found again by (asset number, employee number, unit); Oracle's
 *     columns are refreshed from the file.
 *   - A unit the NOC holds that the file no longer lists is stamped
 *     removed_at — never deleted — and restored if a later file lists it.
 *     Its NOC asset is left as it is.
 *   - Units without a NOC asset are then linked to employees
 *     (OracleAssetEmployeeLinker) and given assets (OracleAssetResolver).
 *     A unit that already has one is never re-decided.
 *
 * Everything runs in one transaction. A dry run does the whole import and
 * rolls it back, so its counts are exactly what the real import would do.
 */
class OracleAssetImporter
{
    private const MIN_YEAR = 1990;

    private const MAX_YEAR = 2100;

    public function __construct(
        private OracleAssetEmployeeLinker $employees,
        private OracleAssetResolver $resolver,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  as OracleAssetSheetReader returns them
     */
    public function import(array $rows, ?string $filename, ?int $userId, bool $dryRun = false): OracleAssetImport
    {
        if (! $dryRun) {
            return DB::transaction(fn () => $this->run($rows, $filename, $userId, true));
        }

        DB::beginTransaction();

        try {
            $import = $this->run($rows, $filename, $userId, false);

            return new OracleAssetImport([
                'filename' => $import->filename,
                'rows' => $import->rows,
                'summary' => $import->summary,
                'notes' => $import->notes,
                'imported_by' => $import->imported_by,
            ]);
        } finally {
            DB::rollBack();
        }
    }

    private function run(array $rows, ?string $filename, ?int $userId, bool $log): OracleAssetImport
    {
        $notes = [];
        $counts = [
            'computers' => 0, 'skipped_other' => 0, 'skipped_invalid' => 0,
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'restored' => 0, 'removed' => 0,
        ];

        $units = [];
        $ordinals = [];

        foreach ($rows as $row) {
            $line = $row['row'] ?? '?';
            $number = $this->identifier($row['asset_number'] ?? null);
            $empNo = $this->identifier($row['emp_no'] ?? null);
            $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['description'] ?? '')));

            if ($number === '' || $empNo === '' || $description === '') {
                $notes[] = "Row {$line}: no asset number, asset name or employee number — left out.";
                $counts['skipped_invalid']++;

                continue;
            }

            $category = AssetLineKind::of($description);

            if ($category === null) {
                $counts['skipped_other']++;

                continue;
            }

            $unit = $ordinals[$number.'|'.$empNo] = ($ordinals[$number.'|'.$empNo] ?? 0) + 1;

            $units[OracleAsset::keyOf($number, $empNo, $unit)] = [
                'asset_number' => mb_substr($number, 0, 40),
                'unit' => $unit,
                'description' => mb_substr($description, 0, 255),
                'purchase_date' => $this->date($row['purchase_date'] ?? null, "Row {$line}: P Date", $notes),
                'end_date' => $this->date($row['end_date'] ?? null, "Row {$line}: End Date", $notes),
                'emp_no' => mb_substr($empNo, 0, 50),
                'emp_name' => ($name = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['emp_name'] ?? '')))) !== '' ? mb_substr($name, 0, 255) : null,
                'category' => $category,
            ];
            $counts['computers']++;
        }

        if ($units === []) {
            throw new RuntimeException($rows === []
                ? 'The file has no rows under its header.'
                : 'The file has no laptops or desktops in it.');
        }

        $import = OracleAssetImport::create(['filename' => $filename, 'rows' => count($rows), 'imported_by' => $userId]);

        $existing = OracleAsset::query()->get()->keyBy(fn (OracleAsset $unit) => $unit->key());
        $listed = [];

        foreach ($units as $key => $attributes) {
            $unit = $existing->get($key);

            if (! $unit) {
                $unit = new OracleAsset($attributes + ['first_import_id' => $import->id]);
                $counts['created']++;
            } else {
                $unit->fill($attributes);

                if ($unit->removed_at !== null) {
                    $unit->removed_at = null;
                    $counts['restored']++;
                } elseif ($unit->isDirty(['description', 'purchase_date', 'end_date', 'emp_name', 'category'])) {
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            $unit->last_import_id = $import->id;
            $unit->save();
            $listed[$key] = $unit;
        }

        foreach ($existing as $key => $unit) {
            if (! isset($listed[$key]) && $unit->removed_at === null) {
                $unit->forceFill(['removed_at' => now()])->save();
                $counts['removed']++;
            }
        }

        $open = collect($listed)->reject(fn (OracleAsset $unit) => $unit->isResolved())->values();

        foreach ($this->employees->linkAll($open) as $method => $count) {
            $counts['employee_'.$method] = $count;
        }
        $open->each(fn (OracleAsset $unit) => $unit->isDirty() ? $unit->save() : null);

        $counts += $this->resolver->resolve($open);
        $counts['in_register'] = OracleAsset::query()->listed()->count();

        $import->forceFill(['summary' => $counts, 'notes' => $notes !== [] ? array_slice($notes, 0, 200) : null])->save();

        if ($log) {
            ActivityLog::create([
                'model_type' => OracleAssetImport::class,
                'model_id' => $import->id,
                'model_label' => $filename,
                'action' => 'oracle_asset_import',
                'changes' => ['summary' => $counts, 'notes' => count($notes)],
                'user_id' => $userId,
            ]);
        }

        return $import;
    }

    /** An asset or employee number as text: Excel hands 1001946 over as an integer or 1001946.0. */
    private function identifier(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value ? (string) (int) $value : (string) $value;
        }

        $text = trim((string) $value);

        return preg_match('/^\d+\.0+$/', $text) ? substr($text, 0, strpos($text, '.')) : $text;
    }

    /** @param  list<string>  $notes */
    private function date(mixed $value, string $label, array &$notes): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        try {
            $date = null;

            if ($value instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($value);
            } elseif (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{4,5}(\.\d+)?$/', trim($value)))) {
                $date = DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value));
            } else {
                $text = trim((string) $value);

                foreach (['!d-M-y', '!d-M-Y', '!Y-m-d', '!Y-m-d H:i:s', '!d/m/Y', '!m/d/Y', '!Y/m/d'] as $format) {
                    $parsed = DateTimeImmutable::createFromFormat($format, $text);

                    if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                        $date = $parsed;
                        break;
                    }
                }
            }

            if (! $date || (int) $date->format('Y') < self::MIN_YEAR || (int) $date->format('Y') > self::MAX_YEAR) {
                throw new InvalidArgumentException('not a date');
            }

            return $date->format('Y-m-d');
        } catch (Throwable) {
            $notes[] = "{$label} '".(is_scalar($value) ? $value : '?')."' is not a date — imported without it.";

            return null;
        }
    }
}
