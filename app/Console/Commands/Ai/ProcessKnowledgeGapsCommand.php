<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeGap;
use App\Models\AiSetting;
use App\Services\Ai\KnowledgeGapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps AI Assistant ▸ Knowledge gaps current: opens again what a deleted or
 * unpublished article had closed, embeds new questions, groups the wordings
 * of one question, and closes the questions an article now answers.
 *
 * Only a new question costs an Azure call; the rest compares stored vectors.
 */
class ProcessKnowledgeGapsCommand extends Command
{
    protected $signature = 'ai:knowledge-gaps';

    protected $description = 'Group the AI Assistant\'s unanswered questions and close the ones an article now answers';

    public function handle(KnowledgeGapService $service): int
    {
        if (! AiSetting::get()->embeddingsConfigured()) {
            $this->comment('Embeddings are not configured, so there is nothing to compare.');

            return self::SUCCESS;
        }

        $reopened = $service->reopenOrphans();
        $open = AiKnowledgeGap::open()->get();

        try {
            $embedded = $service->embedMissing($open);
        } catch (Throwable $e) {
            $this->warn('New questions could not be embedded: '.$e->getMessage());

            return self::SUCCESS;
        }

        $questions = $service->regroup($open);
        $result = $service->recheck($open);

        $this->info("{$open->count()} open, as {$questions} questions: {$embedded} embedded, {$result['closed']} closed by an article, {$reopened} opened again.");

        return self::SUCCESS;
    }
}
