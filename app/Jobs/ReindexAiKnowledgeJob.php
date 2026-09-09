<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\AiSetting;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Full re-index sweep, triggered by "Reindex All" on the AI Assistant
 * Knowledge page. The per-save reindex in AiKnowledgeController only covers
 * an article going forward — this is what recovers articles whose embedding
 * failed silently in the past (e.g. no embedding_deployment configured yet,
 * or an Azure OpenAI outage at save time) once the settings are fixed.
 *
 * Queued rather than run inline because embedding every article is one Azure
 * OpenAI call per article — drained by the queue-drainer scheduled task
 * (routes/console.php), same pattern as SyncGdmsDeviceAccountsJob.
 */
class ReindexAiKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes max

    public int $tries = 1;      // don't retry — just log the outcome

    protected ?int $userId;

    public function __construct(?int $userId = null)
    {
        $this->userId = $userId;
        $this->onQueue('default');
    }

    public function handle(KnowledgeIndexer $indexer): void
    {
        try {
            $result = $indexer->reindexAll();
        } catch (\Throwable $e) {
            // Still mark finished — otherwise the page reports "still
            // running" forever for a sweep that actually crashed.
            $result = ['indexed' => 0, 'failed' => 0, 'failed_titles' => [], 'error' => $e->getMessage()];

            $this->markFinished($result);

            throw $e; // let the queue log the failure too
        }

        $this->markFinished($result);

        ActivityLog::create([
            'model_type' => 'AiKnowledgeArticle',
            'model_id' => 0,
            'action' => 'reindexed_all',
            'changes' => $result,
            'user_id' => $this->userId,
        ]);
    }

    private function markFinished(array $result): void
    {
        AiSetting::get()->update([
            'last_reindex_finished_at' => now(),
            'last_reindex_result' => $result,
        ]);
    }
}
