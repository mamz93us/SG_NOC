<?php

namespace App\Console\Commands\Ai;

use App\Services\Ai\DocumentIndexer;
use Illuminate\Console\Command;

/**
 * Extracts and indexes text from every published PDF in the employee
 * document library (portal_documents), so the AI Assistant can search and
 * cite them alongside hand-written knowledge articles.
 *
 * Scheduled, not run on save — PDF parsing plus an embedding call per new
 * chunk is too slow to do inline on an admin's publish click.
 */
class IndexKnowledgeDocumentsCommand extends Command
{
    protected $signature = 'ai:index-documents';

    protected $description = 'Extract text from published PDF documents and index them for the AI Assistant to search.';

    public function handle(DocumentIndexer $indexer): int
    {
        $count = $indexer->reindexAll();

        $this->info("Indexed {$count} published PDF document(s).");

        return self::SUCCESS;
    }
}
