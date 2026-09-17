<?php

namespace App\Console\Commands;

use App\Services\Itam\Oracle\OracleAssetImporter;
use App\Services\Itam\Oracle\OracleAssetSheetReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Imports Oracle's fixed-asset register from a file, as the register page does.
 *
 *   php artisan itam:import-oracle-assets "/tmp/assets 7-2026.xlsx" --dry-run
 *   php artisan itam:import-oracle-assets "/tmp/assets 7-2026.xlsx" --user=1
 *
 * --dry-run does the whole import and rolls it back, printing what it would do.
 */
class ItamImportOracleAssets extends Command
{
    protected $signature = 'itam:import-oracle-assets
        {file : The register exported from Oracle (.xlsx, .xls or .csv)}
        {--dry-run : Show what the import would do, save nothing}
        {--user= : The user id to record as the importer}';

    protected $description = "Import the laptops and desktops in Oracle's fixed-asset register and match them to Intune";

    public function handle(OracleAssetSheetReader $reader, OracleAssetImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $reader->read($path, pathinfo($path, PATHINFO_EXTENSION));
            $import = $importer->import($rows, basename($path), $this->option('user') !== null ? (int) $this->option('user') : null, $dryRun);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'DRY RUN — nothing saved. ' : '').$import->summaryLine());

        $labels = [
            'computers' => 'Laptops and desktops',
            'skipped_other' => 'Left out (software, monitors, other)',
            'skipped_invalid' => 'Left out (unreadable rows)',
            'created' => 'New in the register',
            'updated' => 'Changed',
            'unchanged' => 'Unchanged',
            'restored' => 'Back in Oracle',
            'removed' => 'No longer in Oracle',
            'employee_number' => 'Holder by Oracle number',
            'employee_number_branch' => 'Holder by Oracle number, narrowed by branch',
            'employee_name' => 'Holder by name',
            'employee_ambiguous' => 'Several employees hold the number',
            'employee_none' => 'Holder not in the NOC',
            'linked_intune' => 'Matched to an Intune device',
            'linked_serial' => '  of which by serial',
            'created_devices' => 'New assets, not in Intune',
            'no_employee' => 'Waiting for a holder',
            'in_register' => 'Units in the register now',
        ];

        $this->table(['', 'Count'], collect($labels)
            ->filter(fn ($label, $key) => array_key_exists($key, $import->summary ?? []))
            ->map(fn ($label, $key) => [$label, $import->stat($key)])
            ->values()
            ->all());

        foreach (array_slice($import->notes ?? [], 0, 20) as $note) {
            $this->line("  {$note}");
        }

        return self::SUCCESS;
    }
}
