<?php

namespace App\Console\Commands\Ai;

use App\Models\AiSetting;
use App\Models\AiWebPage;
use App\Models\AiWebSource;
use App\Services\Ai\Web\WebCrawler;
use Illuminate\Console\Command;

/**
 * Reads the websites allowed on AI Assistant Knowledge ▸ Websites into the
 * knowledge base, oldest first. WebCrawler does the work; this gives it a
 * time budget, and a round cut off by it carries on at the next run.
 */
class CrawlWebsitesCommand extends Command
{
    protected $signature = 'ai:crawl-websites {--max-seconds=240 : Start no new page after this long}';

    protected $description = 'Read the websites allowed on AI Assistant Knowledge into the knowledge base';

    public function handle(WebCrawler $crawler): int
    {
        if (! AiWebSource::due()->exists()) {
            $this->comment('No website is due.');

            return self::SUCCESS;
        }

        $settings = AiSetting::get();

        if (! $settings->embeddingsConfigured()) {
            // Waiting rather than failing: fixing the settings is the cure.
            $this->warn('Websites are waiting: '.($settings->configurationIssue() ?? 'no embedding deployment is configured.'));

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));

        foreach (AiWebSource::due()->orderBy('id')->get() as $source) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $this->line("#{$source->id} {$source->name}");

            $finished = $crawler->work($source, $deadline);
            $source->refresh();
            $read = $source->pages()->whereIn('status', [AiWebPage::INDEXED, AiWebPage::UNCHANGED])->count();

            $this->line("  {$source->statusLabel()}: {$read} of {$source->pages()->count()} pages read".($source->error ? " — {$source->error}" : ''));

            if (! $finished) {
                break; // out of time; the round carries on next run
            }
        }

        return self::SUCCESS;
    }
}
