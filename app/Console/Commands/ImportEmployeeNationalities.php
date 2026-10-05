<?php

namespace App\Console\Commands;

use App\Services\People\NationalityImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads Oracle's nationality export (PERSON_NUMBER, SYSTEM_NATIONALITY) onto
 * employee records. Oracle's API does not send nationality, so this sheet is
 * the only source; run it again with a fresh export to pick up new joiners.
 *
 * It adds and corrects, never erases. See NationalityImporter.
 */
class ImportEmployeeNationalities extends Command
{
    protected $signature = 'employees:import-nationalities
                            {file : Path to Oracle\'s nationality export (.xlsx)}
                            {--dry-run : Report what would be written, and write nothing}';

    protected $description = 'Set each employee\'s nationality from Oracle\'s nationality export';

    public function handle(NationalityImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        try {
            $result = $importer->importFile($file, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%d people in the sheet (%d with no nationality, %d listed twice with different ones).',
            $result['sheet_rows'], $result['blank'], $result['conflicting'],
        ));
        $this->line(sprintf(
            '%d matched to an employee: %d to set, %d already right. %d are not in the NOC, %d share their number with another record.',
            $result['matched'], $result['set'], $result['unchanged'], $result['not_in_noc'], $result['ambiguous'],
        ));
        $this->line(collect($result['nationalities'])->map(fn ($count, $name) => "{$name} {$count}")->implode(', '));

        if ($result['matched'] === 0) {
            $this->error('Nobody in the sheet matched an employee, so this is probably not the right file.');

            return self::FAILURE;
        }

        if ($result['dry_run']) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
