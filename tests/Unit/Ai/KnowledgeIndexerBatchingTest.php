<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiSetting;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Embedding in batches. The 68-page Arabic labor law imported from PDF came
 * to 428 chunks and ~100k tokens against an embedding quota of 130k tokens a
 * minute, so one request for everything would leave any longer document
 * unindexable. Pinned here: requests stay inside the batch budget, nothing is
 * written unless every batch embedded, and only background callers wait out
 * a 429.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['ai_knowledge_imports', 'ai_knowledge_chunks', 'ai_knowledge_articles', 'portal_documents', 'ai_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    // Only as the parent the knowledge tables' foreign keys name.
    Schema::create('portal_documents', function (Blueprint $t) {
        $t->id();
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
});

/** A published article of $sections headed sections, each one chunk of about 1,400 characters. */
function batchingArticle(int $sections): AiKnowledgeArticle
{
    $body = collect(range(1, $sections))
        ->map(fn (int $n) => "## Article {$n}\n".str_repeat("Clause {$n} of the law. ", 58))
        ->implode("\n\n");

    return AiKnowledgeArticle::create(['title' => 'Labor Law', 'body' => $body, 'audience' => 'all', 'is_published' => true]);
}

/** One embedding per input, whatever the batch size. */
function embeddingsForEveryInput(): void
{
    Http::fake(['*/embeddings*' => fn (Request $request) => Http::response([
        'data' => array_map(fn ($i) => ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]], array_keys($request['input'])),
    ])]);
}

it('embeds a long article in batches inside the budget and stores every chunk', function () {
    embeddingsForEveryInput();
    $article = batchingArticle(60); // ~84,000 characters

    expect(app(KnowledgeIndexer::class)->indexArticle($article))->toBeTrue()
        ->and($article->chunks()->count())->toBe(60)
        ->and($article->chunks()->first()->embeddingVector())->toHaveCount(3);

    $requests = Http::recorded();

    expect($requests->count())->toBeGreaterThan(1);

    foreach ($requests as [$request]) {
        expect(array_sum(array_map('mb_strlen', $request['input'])))->toBeLessThanOrEqual(KnowledgeIndexer::BATCH_CHARS)
            ->and(count($request['input']))->toBeLessThanOrEqual(KnowledgeIndexer::BATCH_INPUTS);
    }
});

it('embeds each chunk under its article title and heading, so an article can be found by its name', function () {
    embeddingsForEveryInput();

    $article = AiKnowledgeArticle::create([
        'title' => 'Labor Law',
        'title_ar' => 'نظام العمل',
        'body' => "## Article 77:\nUnless the contract specifies a defined compensation.",
        'body_ar' => "## المادة السابعة والسبعون:\nما لم يتضمن العقد تعويضًا محددًا.",
        'audience' => 'all',
        'is_published' => true,
    ]);

    expect(app(KnowledgeIndexer::class)->indexArticle($article))->toBeTrue()
        ->and(Http::recorded()->flatMap(fn (array $pair) => $pair[0]['input'])->all())->toBe([
            "Labor Law\nArticle 77:\n\nUnless the contract specifies a defined compensation.",
            "نظام العمل\nالمادة السابعة والسبعون:\n\nما لم يتضمن العقد تعويضًا محددًا.",
        ])
        ->and($article->chunks()->orderBy('id')->pluck('content')->all())->toBe([
            'Unless the contract specifies a defined compensation.',
            'ما لم يتضمن العقد تعويضًا محددًا.',
        ]);
});

it('writes nothing when a later batch fails', function () {
    Http::fake(['*/embeddings*' => Http::sequence()
        ->push(['data' => array_map(fn ($i) => ['index' => $i, 'embedding' => [0.1]], range(0, 99))])
        ->pushStatus(500)]);

    $article = batchingArticle(60);

    expect(app(KnowledgeIndexer::class)->indexArticle($article))->toBeFalse()
        ->and($article->chunks()->count())->toBe(0);
});

it('waits out Azure throttling when a background caller asks it to', function () {
    Sleep::fake();
    Http::fake(['*/embeddings*' => Http::sequence()
        ->pushStatus(429)
        ->push(['data' => [['index' => 0, 'embedding' => [0.1, 0.2]]]])]);

    $article = batchingArticle(1);

    expect(app(KnowledgeIndexer::class)->indexArticle($article, waitWhenThrottled: true))->toBeTrue()
        ->and($article->chunks()->count())->toBe(1);

    Sleep::assertSleptTimes(1);
});

it('fails straight away on a 429 when an admin is waiting on the save', function () {
    Sleep::fake();
    Http::fake(['*/embeddings*' => Http::sequence()->pushStatus(429)]);

    $article = batchingArticle(1);

    expect(app(KnowledgeIndexer::class)->indexArticle($article))->toBeFalse()
        ->and($article->chunks()->count())->toBe(0);

    Sleep::assertNeverSlept();
});

it('cuts pieces into batches by characters and inputs, in order and keeping their keys', function () {
    $piece = fn (int $length) => ['content' => str_repeat('x', $length)];

    $byChars = ['a' => $piece(10), 'b' => $piece(10), 'c' => $piece(25), 'd' => $piece(1)];

    expect(KnowledgeIndexer::batches($byChars, maxChars: 20, maxInputs: 10))->toBe([
        ['a' => $byChars['a'], 'b' => $byChars['b']],
        ['c' => $byChars['c']], // longer than the budget on its own: still sent, alone
        ['d' => $byChars['d']],
    ]);

    $byInputs = ['p' => $piece(1), 'q' => $piece(1), 'r' => $piece(1), 's' => $piece(1), 't' => $piece(1)];

    expect(KnowledgeIndexer::batches($byInputs, maxChars: 100, maxInputs: 2))->toBe([
        ['p' => $byInputs['p'], 'q' => $byInputs['q']],
        ['r' => $byInputs['r'], 's' => $byInputs['s']],
        ['t' => $byInputs['t']],
    ]);
});
