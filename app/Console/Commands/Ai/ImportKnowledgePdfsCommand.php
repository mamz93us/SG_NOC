<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeImport;
use App\Models\AiSetting;
use App\Services\Ai\PdfKnowledgeImporter;
use Illuminate\Console\Command;

/**
 * Reads and translates the PDFs uploaded on the AI Assistant Knowledge page,
 * oldest first. PdfKnowledgeImporter does the work.
 *
 * Every minute from the scheduler, never inline: a page is one gpt-4o call of
 * ten seconds to a minute, and a manual is dozens of pages. It starts no new
 * page after --max-seconds; the import is saved where it stopped, and the next
 * run carries on from there.
 */
class ImportKnowledgePdfsCommand extends Command
{
    protected $signature = 'ai:import-pdfs {--max-seconds=240 : Start no new page after this long}';

    protected $description = 'Read, translate and add the PDFs queued on the AI Assistant Knowledge page';

    public function handle(PdfKnowledgeImporter $importer): int
    {
        if (! AiKnowledgeImport::active()->exists()) {
            $this->comment('Nothing queued.');

            return self::SUCCESS;
        }

        $settings = AiSetting::get();

        if (! $settings->isConfigured()) {
            // Waiting rather than failing: switching the assistant back on is the fix.
            $this->warn('PDFs are waiting: '.$settings->configurationIssue());

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));

        while (microtime(true) < $deadline) {
            $import = AiKnowledgeImport::active()->orderBy('id')->first();

            if (! $import) {
                break;
            }

            $this->line("#{$import->id} {$import->file_name}");

            $finished = $importer->work($import, $deadline);
            $import = $import->fresh();

            $this->line($import
                ? '  '.$import->statusLabel().($import->error ? " — {$import->error}" : '')
                : '  deleted while it was being read');

            if (! $finished) {
                break; // out of time, or a page failed and waits for the next run
            }
        }

        return self::SUCCESS;
    }
}
