<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeArticle;
use App\Models\AiSetting;
use App\Services\Ai\ArticleClassifier;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Fills in the category and tags of the knowledge articles queued for it: by
 * the Knowledge page's "Category & tags by AI" buttons, and on creation for any
 * article saved without them. ArticleClassifier does the reading.
 *
 * One short chat call an article. A reply that cannot be used is written on
 * the article, which leaves the queue; throttling or an Azure outage leaves it
 * queued for the next run.
 */
class ClassifyArticlesCommand extends Command
{
    protected $signature = 'ai:classify-articles {--max-seconds=50 : Start no new article after this long}';

    protected $description = 'Fill in the category and tags of knowledge articles with AI';

    public function handle(ArticleClassifier $classifier): int
    {
        if (! AiKnowledgeArticle::whereNotNull('ai_classify')->exists()) {
            $this->comment('Nothing queued.');

            return self::SUCCESS;
        }

        $settings = AiSetting::get();

        if (! $settings->isConfigured()) {
            // Waiting rather than failing: switching the assistant back on is the fix.
            $this->warn('Articles are waiting: '.$settings->configurationIssue());

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));

        while (microtime(true) < $deadline) {
            $article = AiKnowledgeArticle::whereNotNull('ai_classify')->orderBy('id')->first();

            if (! $article) {
                break;
            }

            try {
                $assigned = $classifier->assign($article, $article->ai_classify);
            } catch (Throwable $e) {
                if (self::worthRetrying($e)) {
                    $this->warn("#{$article->id}: {$e->getMessage()} The rest wait for the next run.");

                    break;
                }

                $article->forceFill(['ai_classify' => null, 'ai_classify_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                $this->error("#{$article->id} {$article->title}: {$e->getMessage()}");

                continue;
            }

            $filed = array_filter([
                $assigned['category'] ?? null,
                isset($assigned['tags']) ? '['.implode(', ', $assigned['tags']).']' : null,
            ]);

            $this->line("#{$article->id} {$article->title}: ".($filed === [] ? 'already filled in' : implode(' ', $filed)));
        }

        return self::SUCCESS;
    }

    /** Throttling, an Azure outage or the network, unlike a reply that makes no sense. */
    private static function worthRetrying(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || (bool) preg_match('/HTTP (429|5\d\d)|cURL error|timed out/i', $e->getMessage());
    }
}
