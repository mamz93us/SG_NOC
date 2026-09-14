<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiSetting;
use App\Models\AiWebPage;
use App\Models\AiWebSource;
use App\Services\Ai\Web\HostGuard;
use App\Services\Ai\Web\HtmlExtractor;
use App\Services\Ai\Web\RobotsTxt;
use App\Services\Ai\Web\UrlTools;
use App\Services\Ai\Web\WebCrawler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Websites read into the knowledge base. The site, Azure and DNS are all
 * faked: under test are the crawler's decisions — what it follows, what it
 * translates, what it keeps, what it removes, and where it refuses to go.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['ai_web_pages', 'ai_web_sources', 'ai_knowledge_imports', 'ai_knowledge_chunks', 'ai_knowledge_articles', 'portal_documents', 'ai_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('portal_documents', function (Blueprint $t) {
        $t->id();
        $t->string('title')->nullable();
        $t->string('title_ar')->nullable();
        $t->boolean('is_published')->default(false);
        $t->timestamps();
    });

    foreach ([
        '2026_09_08_100001_create_ai_settings_table',
        '2026_09_09_100000_add_reindex_tracking_to_ai_settings_table',
        '2026_09_09_100100_add_knowledge_match_threshold_to_ai_settings_table',
        '2026_09_08_100003_create_ai_knowledge_articles_table',
        '2026_09_08_100004_create_ai_knowledge_chunks_table',
        '2026_09_09_120000_add_locale_to_ai_knowledge_chunks_table',
        '2026_09_14_110001_create_ai_knowledge_imports_table',
        '2026_09_14_120001_add_source_to_ai_knowledge_chunks_table',
        '2026_09_14_140001_create_ai_web_sources_table',
        '2026_09_14_150001_add_ai_classification_to_ai_knowledge_articles_table',
    ] as $migration) {
        (require database_path("migrations/{$migration}.php"))->up();
    }

    AiSetting::create([
        'enabled' => true,
        'azure_endpoint' => 'https://azure.test',
        'azure_api_key' => 'test-key',
        'chat_deployment' => 'gpt-4o',
        'embedding_deployment' => 'text-embedding-3-small',
    ]);

    Sleep::fake();
    app()->instance(HostGuard::class, new HostGuard(fn (string $host) => ['93.184.216.34']));
});

function webSource(array $attributes = []): AiWebSource
{
    return AiWebSource::create(array_merge([
        'name' => 'HR policies',
        'start_url' => 'https://hr.example.com/policies/',
        'scope_url' => 'https://hr.example.com/policies/',
        'max_pages' => 10,
        'max_depth' => 2,
        'refresh_days' => 7,
        'publish' => true,
        'audience' => 'all',
        'status' => AiWebSource::QUEUED,
    ], $attributes));
}

function webHtml(string $title, string $main, string $lang = 'en'): string
{
    return "<!doctype html><html lang=\"{$lang}\"><head><title>{$title}</title></head><body>"
        .'<nav><a href="/policies/leave">Leave</a></nav>'
        ."<main>{$main}</main><footer>© Samir Group</footer></body></html>";
}

/**
 * The site, Azure and nothing else, from one fake: $site maps a URL to
 * [status, body, content type] and can be changed between rounds.
 */
function webFake(array &$site): void
{
    Http::fake(function (Request $request) use (&$site) {
        if (str_contains($request->url(), '/chat/completions')) {
            return Http::response([
                'choices' => [['message' => ['content' => 'Translated: '.mb_substr((string) $request['messages'][1]['content'], 0, 60)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]);
        }

        if (str_contains($request->url(), '/embeddings')) {
            return Http::response(['data' => array_map(fn ($i) => ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]], array_keys($request['input']))]);
        }

        [$status, $body, $type] = $site[$request->url()] ?? [404, 'Not found', 'text/html'];

        return Http::response($body, $status, ['Content-Type' => $type]);
    });
}

function webStartPage(): array
{
    return [200, webHtml('HR policies', '<h1>HR policies</h1><p>'.str_repeat('Everything about working at Samir Group, in one place. ', 5).'</p>'
        .'<a href="leave">Leave</a> <a href="/news/today">News</a> <a href="forms/request.pdf">Form</a>'), 'text/html; charset=utf-8'];
}

it('keeps a page as Markdown, drops its menus and scripts, and collects its links', function () {
    $page = HtmlExtractor::extract(
        '<html lang="ar"><head><title>Leave Policy</title><script>track()</script></head><body>'
        .'<header><a href="/">Home</a></header><nav><a href="/policies/leave">Leave</a></nav>'
        .'<main><h1>Annual leave</h1><p>Employees get <strong>21 days</strong> a year.</p>'
        .'<ul><li>Apply two weeks ahead</li><li>Unused days carry over</li></ul>'
        .'<table><tr><th>Years</th><th>Days</th></tr><tr><td>1-5</td><td>21</td></tr></table>'
        .'<a href="forms/leave-request.pdf">Form</a> <a href="https://other.example.org/x">Elsewhere</a></main>'
        .'<footer>© Samir Group</footer></body></html>',
        'https://hr.example.com/policies/index.html'
    );

    expect($page['title'])->toBe('Leave Policy')
        ->and($page['language'])->toBe('ar')
        ->and($page['markdown'])->toBe("## Annual leave\n\nEmployees get 21 days a year.\n\n- Apply two weeks ahead\n- Unused days carry over\n\n| Years | Days |\n| --- | --- |\n| 1-5 | 21 |\n\nForm Elsewhere")
        ->and($page['links'])->toBe([
            'https://hr.example.com/',
            'https://hr.example.com/policies/leave',
            'https://hr.example.com/policies/forms/leave-request.pdf',
            'https://other.example.org/x',
        ]);
});

it('resolves and compares addresses the way the crawler needs', function () {
    expect(UrlTools::resolve('../leave#top', 'https://HR.example.com:443/policies/hr/index.html'))->toBe('https://hr.example.com/policies/leave')
        ->and(UrlTools::resolve('mailto:hr@example.com', 'https://hr.example.com/'))->toBeNull()
        ->and(UrlTools::folder('https://hr.example.com/policies/leave.html?print=1'))->toBe('https://hr.example.com/policies/')
        ->and(UrlTools::inScope('http://hr.example.com/policies/leave', 'https://hr.example.com/policies/'))->toBeTrue()
        ->and(UrlTools::inScope('https://hr.example.com/news/today', 'https://hr.example.com/policies/'))->toBeFalse()
        ->and(UrlTools::inScope('https://hr.example.com.evil.org/policies/', 'https://hr.example.com/policies/'))->toBeFalse()
        ->and(UrlTools::looksLikePage('https://hr.example.com/forms/leave.PDF'))->toBeFalse()
        ->and(UrlTools::looksLikePage('https://hr.example.com/policies/leave'))->toBeTrue();
});

it('reads only public addresses unless a website allows internal ones', function () {
    $intranet = new HostGuard(fn () => ['10.1.8.20']);

    expect(fn () => $intranet->check('intranet.samirgroup.com', false))->toThrow(RuntimeException::class, 'internal address')
        ->and($intranet->check('intranet.samirgroup.com', true))->toBe('10.1.8.20')
        ->and((new HostGuard(fn () => ['93.184.216.34']))->check('example.com', false))->toBe('93.184.216.34')
        ->and(fn () => (new HostGuard(fn () => []))->check('nowhere.invalid', false))->toThrow(RuntimeException::class, 'does not resolve')
        ->and(fn () => (new HostGuard)->check('127.0.0.1', false))->toThrow(RuntimeException::class, 'internal address');
});

it('follows robots.txt: the group naming us first, the longest rule deciding', function () {
    $robots = RobotsTxt::parse(
        "User-agent: *\nDisallow: /\n\nUser-agent: SamirGroup-KnowledgeBot\nDisallow: /policies/drafts/\nAllow: /policies/drafts/public$\nDisallow: /*.php",
        WebCrawler::USER_AGENT
    );

    expect($robots->allows('/policies/leave'))->toBeTrue()
        ->and($robots->allows('/policies/drafts/new'))->toBeFalse()
        ->and($robots->allows('/policies/drafts/public'))->toBeTrue()
        ->and($robots->allows('/index.php?id=3'))->toBeFalse()
        ->and(RobotsTxt::parse("User-agent: *\nDisallow: /private", WebCrawler::USER_AGENT)->allows('/private/files'))->toBeFalse();
});

it('reads a site into articles, translating an Arabic page, and follows only links inside its scope', function () {
    $site = [
        'https://hr.example.com/robots.txt' => [404, '', 'text/plain'],
        'https://hr.example.com/policies/' => webStartPage(),
        'https://hr.example.com/policies/leave' => [200, webHtml('الإجازات', '<h1>الإجازة السنوية</h1><p>'.str_repeat('يستحق العامل إجازة سنوية لا تقل عن واحد وعشرين يوما. ', 5).'</p>', 'ar'), 'text/html'],
    ];
    webFake($site);

    $source = webSource();

    expect(app(WebCrawler::class)->work($source, microtime(true) + 60))->toBeTrue();

    $source->refresh();

    expect($source)->toMatchArray([
        'status' => AiWebSource::IDLE,
        'pages_found' => 2,
        'pages_indexed' => 2,
        'prompt_tokens' => 200,
        'completion_tokens' => 100,
    ])
        ->and($source->next_crawl_at)->not->toBeNull()
        ->and(AiWebPage::orderBy('id')->pluck('url')->all())->toBe(['https://hr.example.com/policies/', 'https://hr.example.com/policies/leave']);

    $leave = AiWebPage::where('url', 'https://hr.example.com/policies/leave')->first();

    expect($leave->language)->toBe('ar')
        ->and($leave->article->title)->toStartWith('Translated:')
        ->and($leave->article->title_ar)->toBe('الإجازات')
        ->and($leave->article->body_ar)->toContain('يستحق العامل إجازة سنوية')
        ->and($leave->article->is_published)->toBeTrue()
        ->and($leave->article->category)->toBeNull()
        ->and($leave->article->ai_classify)->toBe(AiKnowledgeArticle::CLASSIFY_MISSING)
        ->and($leave->article->chunks()->count())->toBeGreaterThan(0)
        ->and(AiWebPage::where('url', 'https://hr.example.com/policies/')->first()->article->body)->toContain('Everything about working at Samir Group');
});

it('spends nothing on a page that has not changed, and removes the article of a page that is gone', function () {
    $site = [
        'https://hr.example.com/robots.txt' => [404, '', 'text/plain'],
        'https://hr.example.com/policies/' => webStartPage(),
        'https://hr.example.com/policies/leave' => [200, webHtml('Leave', '<h1>Annual leave</h1><p>'.str_repeat('Employees are entitled to twenty-one days of annual leave. ', 5).'</p>'), 'text/html'],
    ];
    webFake($site);

    $source = webSource();
    app(WebCrawler::class)->work($source, microtime(true) + 60);
    $leaveArticle = AiWebPage::where('url', 'https://hr.example.com/policies/leave')->value('article_id');
    $requestsBefore = count(Http::recorded(fn (Request $request) => ! str_contains($request->url(), 'hr.example.com')));

    // A week later: the start page is the same, the leave page has been taken down.
    $site['https://hr.example.com/policies/leave'] = [404, 'Not found', 'text/html'];
    $source->refresh();
    app(WebCrawler::class)->work($source, microtime(true) + 60);

    expect(AiWebPage::where('url', 'https://hr.example.com/policies/')->value('status'))->toBe(AiWebPage::UNCHANGED)
        ->and(AiWebPage::where('url', 'https://hr.example.com/policies/leave')->first())->toMatchArray(['status' => AiWebPage::GONE, 'article_id' => null])
        ->and(AiKnowledgeArticle::find($leaveArticle))->toBeNull()
        ->and($source->refresh()->pages_indexed)->toBe(1)
        ->and(count(Http::recorded(fn (Request $request) => ! str_contains($request->url(), 'hr.example.com'))))->toBe($requestsBefore);
});

it('refuses a website that resolves to an internal address, and says why', function () {
    app()->instance(HostGuard::class, new HostGuard(fn () => ['172.16.8.4']));
    $site = ['https://hr.example.com/policies/' => webStartPage()];
    webFake($site);

    $source = webSource();
    app(WebCrawler::class)->work($source, microtime(true) + 60);

    expect($source->refresh()->status)->toBe(AiWebSource::FAILED)
        ->and($source->error)->toContain('internal address')
        ->and(AiKnowledgeArticle::count())->toBe(0);

    Http::assertNothingSent();
});
