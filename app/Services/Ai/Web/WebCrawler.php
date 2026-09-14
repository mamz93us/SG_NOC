<?php

namespace App\Services\Ai\Web;

use App\Models\AiKnowledgeArticle;
use App\Models\AiWebPage;
use App\Models\AiWebSource;
use App\Services\Ai\KnowledgeIndexer;
use App\Services\Ai\TextTranslator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Reads an allowed website into the knowledge base one page at a time, and
 * can stop anywhere: `ai:crawl-websites` gives it a time budget, and the rows
 * in ai_web_pages say where a round stands.
 *
 * A round reads every page it knows again and follows links inside the
 * source's scope, up to max_depth clicks from the start and max_pages in all.
 * Each readable page becomes one AiKnowledgeArticle — English body, the Arabic
 * original beside it when the page is Arabic — indexed like any other. An
 * unchanged page costs nothing, and a page that answers 404 or 410 loses its
 * article. A website with no category leaves each page's to the AI
 * (ArticleClassifier), like any article created without one.
 */
class WebCrawler
{
    public const USER_AGENT = 'SamirGroup-KnowledgeBot/1.0 (+https://noc.samirgroup.net)';

    /** Bytes one page may have; a larger page is not read. */
    public const MAX_BYTES = 3_000_000;

    /** Characters of text kept from one page: a guard on what translation costs. */
    public const MAX_CHARACTERS = 60_000;

    /** Less than this is a menu or a JavaScript shell, not a page to answer from. */
    public const MIN_CHARACTERS = 200;

    private const MAX_REDIRECTS = 5;

    /** Between requests: a crawler that reads politely stays welcome. */
    private const PAUSE_SECONDS = 1;

    /** @var array<int, RobotsTxt> per source, for this run */
    private array $robots = [];

    public function __construct(
        private HostGuard $guard,
        private TextTranslator $translator,
        private KnowledgeIndexer $indexer,
    ) {}

    /**
     * Works on the source until its round is finished or $deadline — a
     * microtime — passes.
     *
     * @return bool true when the round finished
     */
    public function work(AiWebSource $source, float $deadline): bool
    {
        if ($source->status !== AiWebSource::CRAWLING) {
            $this->startRound($source);
        }

        while (microtime(true) < $deadline) {
            $page = $source->pages()->where('status', AiWebPage::QUEUED)->orderBy('depth')->orderBy('id')->first();

            if (! $page) {
                $this->finishRound($source);

                return true;
            }

            $this->visit($source, $page);
            Sleep::for(self::PAUSE_SECONDS)->seconds();
        }

        return false;
    }

    private function startRound(AiWebSource $source): void
    {
        $source->pages()->update(['status' => AiWebPage::QUEUED]);

        $start = UrlTools::normalize($source->start_url) ?? $source->start_url;

        AiWebPage::query()->firstOrCreate(
            ['source_id' => $source->id, 'url_hash' => AiWebPage::hashUrl($start)],
            ['url' => $start, 'depth' => 0, 'status' => AiWebPage::QUEUED],
        );

        $source->forceFill(['status' => AiWebSource::CRAWLING, 'error' => null])->save();
    }

    private function finishRound(AiWebSource $source): void
    {
        $read = $source->pages()->whereNotNull('article_id')->whereIn('status', [AiWebPage::INDEXED, AiWebPage::UNCHANGED])->count();
        $start = $source->pages()->where('url_hash', AiWebPage::hashUrl(UrlTools::normalize($source->start_url) ?? $source->start_url))->first();
        $failed = $read === 0 && $start && in_array($start->status, [AiWebPage::FAILED, AiWebPage::GONE, AiWebPage::SKIPPED], true);

        $source->forceFill([
            'status' => $failed ? AiWebSource::FAILED : AiWebSource::IDLE,
            'error' => $failed ? ($start->error ?: "The start page answered HTTP {$start->http_status}.") : null,
            'pages_found' => $source->pages()->count(),
            'pages_indexed' => $read,
            'last_crawled_at' => now(),
            'next_crawl_at' => $source->refresh_days > 0 ? now()->addDays($source->refresh_days) : null,
        ])->save();
    }

    private function visit(AiWebSource $source, AiWebPage $page): void
    {
        try {
            $this->read($source, $page);
        } catch (Throwable $e) {
            Log::warning('ai:crawl-websites: page failed', ['source_id' => $source->id, 'url' => $page->url, 'error' => $e->getMessage()]);

            $page->forceFill([
                'status' => AiWebPage::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'fetched_at' => now(),
            ])->save();
        }
    }

    private function read(AiWebSource $source, AiWebPage $page): void
    {
        $query = parse_url($page->url, PHP_URL_QUERY);
        $path = (parse_url($page->url, PHP_URL_PATH) ?: '/').($query ? "?{$query}" : '');

        if (! $this->robots($source)->allows($path)) {
            $this->skip($page, 'robots.txt asks crawlers not to read this page.');

            return;
        }

        $response = $this->fetch($page->url, $source);

        if ($response['status'] === 0) {
            $this->skip($page, 'It redirects outside the part of the site this website may read ('.$response['final_url'].').');

            return;
        }

        if (in_array($response['status'], [404, 410], true)) {
            $this->removeArticle($page);
            $page->forceFill(['status' => AiWebPage::GONE, 'http_status' => $response['status'], 'error' => null, 'fetched_at' => now()])->save();

            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("The site answered HTTP {$response['status']}.");
        }

        if (! preg_match('#^(text/html|application/xhtml\+xml)#', $response['type'])) {
            $this->skip($page, 'Not a web page ('.($response['type'] ?: 'no content type').').', $response['status']);

            return;
        }

        $extracted = HtmlExtractor::extract($response['body'], $response['final_url'], $response['charset']);
        $this->discover($source, $page, $extracted['links']);

        $markdown = mb_substr($extracted['markdown'], 0, self::MAX_CHARACTERS);

        if (mb_strlen($markdown) < self::MIN_CHARACTERS) {
            $this->skip($page, 'No readable text: the page may build its content with JavaScript.', $response['status']);

            return;
        }

        $title = $extracted['title'] !== '' ? $extracted['title'] : $page->url;
        $hash = hash('sha256', $title."\n".$markdown);
        $article = $page->article_id ? AiKnowledgeArticle::find($page->article_id) : null;

        if ($article && $page->content_hash === $hash) {
            $this->applySettings($article, $source);
            $page->forceFill(['status' => AiWebPage::UNCHANGED, 'http_status' => $response['status'], 'error' => null, 'fetched_at' => now()])->save();

            return;
        }

        // The words decide the language: plenty of Arabic sites say lang="en".
        // A page that declares a third language is translated as well.
        $language = TextTranslator::language($markdown);
        if ($language === 'en' && $extracted['language'] && $extracted['language'] !== 'en') {
            $language = $extracted['language'];
        }

        $content = $language === 'en'
            ? ['title' => $title, 'title_ar' => null, 'body' => $markdown, 'body_ar' => null]
            : [
                'title' => $this->translator->translate($title, 'en') ?: $title,
                'title_ar' => $language === 'ar' ? $title : null,
                'body' => $this->translator->translate($markdown, 'en'),
                'body_ar' => $language === 'ar' ? $markdown : null,
            ];

        $usage = $this->translator->takeUsage();
        $source->forceFill([
            'prompt_tokens' => $source->prompt_tokens + $usage['prompt_tokens'],
            'completion_tokens' => $source->completion_tokens + $usage['completion_tokens'],
        ])->save();

        $attributes = array_merge($this->settings($source), $content, [
            'title' => mb_substr($content['title'], 0, 200),
            'title_ar' => $content['title_ar'] === null ? null : mb_substr($content['title_ar'], 0, 200),
        ]);

        if ($article) {
            $article->update($attributes);
        } else {
            $article = AiKnowledgeArticle::create($attributes + ['tags' => [], 'created_by' => $source->created_by]);
        }

        $indexed = $this->indexer->indexArticle($article, waitWhenThrottled: true);

        $page->forceFill([
            'status' => AiWebPage::INDEXED,
            'http_status' => $response['status'],
            'title' => mb_substr($title, 0, 255),
            'language' => $language,
            // No hash when indexing failed, so the next round does the page again.
            'content_hash' => $indexed ? $hash : null,
            'characters' => mb_strlen($markdown),
            'article_id' => $article->id,
            'error' => $indexed ? null : 'Written, but indexing failed; the next round tries again.',
            'fetched_at' => now(),
        ])->save();
    }

    /** @param  array<int, string>  $links */
    private function discover(AiWebSource $source, AiWebPage $page, array $links): void
    {
        if ($page->depth >= $source->max_depth) {
            return;
        }

        $known = $source->pages()->count();

        foreach ($links as $url) {
            if ($known >= $source->max_pages) {
                return;
            }

            if (! UrlTools::inScope($url, $source->scope_url) || ! UrlTools::looksLikePage($url)) {
                continue;
            }

            $found = AiWebPage::query()->firstOrCreate(
                ['source_id' => $source->id, 'url_hash' => AiWebPage::hashUrl($url)],
                ['url' => $url, 'depth' => $page->depth + 1, 'status' => AiWebPage::QUEUED],
            );

            if ($found->wasRecentlyCreated) {
                $known++;
            }
        }
    }

    /**
     * A GET with each redirect followed by hand: every hop's host checked by
     * HostGuard and its address pinned, and a hop outside the source's scope
     * not followed (status 0).
     *
     * @return array{status: int, type: string, charset: ?string, body: string, final_url: string}
     */
    private function fetch(string $url, AiWebSource $source, bool $keepInScope = true): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $host = trim((string) ($parts['host'] ?? ''), '[]');
            $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
            $address = $this->guard->check($host, (bool) $source->allow_internal);
            $tooLarge = false;

            try {
                // Never 'stream' => true: Guzzle sends a streamed request through
                // PHP's stream wrapper instead of curl, which ignores CURLOPT_RESOLVE
                // and looks the host up again — the pin would quietly stop pinning.
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                ])
                    ->withOptions([
                        'allow_redirects' => false,
                        'curl' => [
                            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? "[{$address}]" : $address)],
                            CURLOPT_PROXY => '', // a proxy would look the host up itself
                            CURLOPT_NOPROGRESS => false,
                            CURLOPT_XFERINFOFUNCTION => function ($curl, int $expected, int $received) use (&$tooLarge): int {
                                $tooLarge = $expected > self::MAX_BYTES || $received > self::MAX_BYTES;

                                return $tooLarge ? 1 : 0; // non-zero stops the transfer
                            },
                        ],
                    ])
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->get($url);
            } catch (Throwable $e) {
                throw $tooLarge ? new RuntimeException('The page is larger than '.intdiv(self::MAX_BYTES, 1_000_000).' MB.') : $e;
            }

            if ($response->redirect()) {
                $next = UrlTools::resolve((string) $response->header('Location'), $url);

                if ($next === null) {
                    throw new RuntimeException('The site redirected to an address that cannot be read.');
                }

                if ($keepInScope && ! UrlTools::inScope($next, $source->scope_url)) {
                    return ['status' => 0, 'type' => '', 'charset' => null, 'body' => '', 'final_url' => $next];
                }

                $url = $next;

                continue;
            }

            $type = strtolower((string) $response->header('Content-Type'));

            return [
                'status' => $response->status(),
                'type' => $type,
                'charset' => preg_match('/charset=["\']?([\w-]+)/', $type, $charset) ? $charset[1] : null,
                'body' => $response->body(),
                'final_url' => $url,
            ];
        }

        throw new RuntimeException('The site redirected more than '.self::MAX_REDIRECTS.' times.');
    }

    /** The site's robots.txt, once per source per run. Anything but a 200 allows everything. */
    private function robots(AiWebSource $source): RobotsTxt
    {
        if (isset($this->robots[$source->id])) {
            return $this->robots[$source->id];
        }

        $parts = parse_url($source->start_url);
        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').'/robots.txt';

        try {
            $response = $this->fetch($url, $source, keepInScope: false);
            $rules = $response['status'] === 200 ? RobotsTxt::parse($response['body'], self::USER_AGENT) : RobotsTxt::allowAll();
        } catch (Throwable) {
            $rules = RobotsTxt::allowAll(); // the page itself reports why the site cannot be reached
        }

        return $this->robots[$source->id] = $rules;
    }

    /**
     * What every article of this website is written with. The category only
     * when the website names one: left empty, the AI files each page, and a
     * later read must not undo that.
     */
    private function settings(AiWebSource $source): array
    {
        return array_filter(['category' => $source->category], fn ($value) => filled($value)) + [
            'audience' => $source->audience,
            'audience_branch_id' => $source->audience === 'branch' ? $source->audience_branch_id : null,
            'audience_department_id' => $source->audience === 'department' ? $source->audience_department_id : null,
            'is_published' => $source->publish,
        ];
    }

    /** An unchanged page still takes the website's current category, audience and publishing. */
    private function applySettings(AiKnowledgeArticle $article, AiWebSource $source): void
    {
        $article->fill($this->settings($source));

        if ($article->isDirty()) {
            $article->save();
            $this->indexer->indexArticle($article, waitWhenThrottled: true);
        }
    }

    private function skip(AiWebPage $page, string $reason, ?int $status = null): void
    {
        $this->removeArticle($page);
        $page->forceFill(['status' => AiWebPage::SKIPPED, 'http_status' => $status, 'error' => $reason, 'fetched_at' => now()])->save();
    }

    private function removeArticle(AiWebPage $page): void
    {
        if ($page->article_id) {
            AiKnowledgeArticle::find($page->article_id)?->delete(); // its chunks go with it
            $page->forceFill(['article_id' => null, 'content_hash' => null]);
        }
    }
}
