<?php

namespace App\Console\Commands;

use App\Services\Exams\ExamBankImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads the bundled practice-exam banks (database/data/exams/*.json), or one
 * given file. Safe to run again: questions are matched on their uid.
 */
class ExamsLoadBank extends Command
{
    protected $signature = 'exams:load-bank {file? : A bank JSON file; all bundled banks when omitted}';

    protected $description = 'Load practice-exam question banks (AZ-900, AI-900, …)';

    public function handle(ExamBankImporter $importer): int
    {
        $files = $this->argument('file') ? [$this->argument('file')] : ExamBankImporter::bundledFiles();
        if ($files === []) {
            $this->warn('No bank files found in database/data/exams.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($files as $file) {
            try {
                $r = $importer->importFile($file);
                $this->info(sprintf('%s: %d added, %d updated, %d unchanged', $r['exam']->code, $r['created'], $r['updated'], $r['unchanged']));
            } catch (Throwable $e) {
                $failed = true;
                $this->error(basename($file).': '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
