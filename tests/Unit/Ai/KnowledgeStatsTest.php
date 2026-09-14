<?php

use App\Models\AiSetting;
use App\Services\Ai\KnowledgeStats;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The statistics page's numbers, from rows whose sizes are known. Embeddings
 * are written as ASCII so SQLite's LENGTH() counts them as bytes, the way
 * MySQL's does.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['ai_knowledge_imports', 'ai_knowledge_chunks', 'ai_knowledge_articles', 'ai_knowledge_gaps', 'portal_documents', 'ai_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('portal_documents', function (Blueprint $t) {
        $t->id();
        $t->string('title')->nullable();
        $t->string('title_ar')->nullable();
        $t->string('file_name')->nullable();
        $t->boolean('is_published')->default(false);
        $t->timestamps();
    });

    foreach ([
        '2026_09_08_100001_create_ai_settings_table',
        '2026_09_09_100000_add_reindex_tracking_to_ai_settings_table',
        '2026_09_09_100100_add_knowledge_match_threshold_to_ai_settings_table',
        '2026_09_08_100003_create_ai_knowledge_articles_table',
        '2026_09_08_100004_create_ai_knowledge_chunks_table',
        '2026_09_08_100007_create_ai_knowledge_gaps_table',
        '2026_09_09_120000_add_locale_to_ai_knowledge_chunks_table',
        '2026_09_14_110001_create_ai_knowledge_imports_table',
        '2026_09_14_120001_add_source_to_ai_knowledge_chunks_table',
    ] as $migration) {
        (require database_path("migrations/{$migration}.php"))->up();
    }

    AiSetting::create(['enabled' => true, 'embedding_deployment' => 'text-embedding-3-small']);
});

function statsArticle(string $title, bool $published = true, string $body = 'x', ?string $bodyAr = null): int
{
    return DB::table('ai_knowledge_articles')->insertGetId([
        'title' => $title, 'body' => $body, 'body_ar' => $bodyAr, 'audience' => 'all',
        'is_published' => $published, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function statsChunk(array $attributes): void
{
    DB::table('ai_knowledge_chunks')->insert(array_merge([
        'article_id' => null,
        'portal_document_id' => null,
        'heading' => null,
        'content' => 'x',
        'content_hash' => bin2hex(random_bytes(32)),
        'locale' => 'en',
        'embedding' => str_repeat('e', 24),
        'token_count' => 1,
        'audience' => 'all',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

it('counts chunks, text and bytes for each source and each language', function () {
    $law = statsArticle('Labor Law', true, str_repeat('a', 900), str_repeat('ب', 700));
    statsArticle('VPN guide'); // published, never indexed
    statsArticle('Draft policy', false);

    DB::table('ai_knowledge_imports')->insert([
        'file_path' => 'ai-knowledge-imports/x.pdf', 'file_name' => 'labor-law.pdf', 'file_size' => 2048,
        'file_hash' => str_repeat('0', 64), 'status' => 'done', 'page_count' => 68, 'pages_done' => 68,
        'prompt_tokens' => 150, 'completion_tokens' => 59, 'audience' => 'all', 'article_id' => $law,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    statsChunk(['article_id' => $law, 'locale' => 'en', 'content' => str_repeat('a', 200), 'source_page' => 3]);
    statsChunk(['article_id' => $law, 'locale' => 'en', 'content' => str_repeat('a', 700), 'source_page' => 4]);
    statsChunk(['article_id' => $law, 'locale' => 'ar', 'content' => str_repeat('ب', 700)]);

    DB::table('ai_knowledge_gaps')->insert([
        ['query_normalized' => 'overtime pay', 'query_sample' => 'overtime pay?', 'hit_count' => 3, 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['query_normalized' => 'printer', 'query_sample' => 'printer', 'hit_count' => 1, 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $stats = app(KnowledgeStats::class)->build();

    expect($stats['totals'])->toMatchArray([
        'articles' => 3,
        'published' => 2,
        'drafts' => 1,
        'written' => 2,
        'imported' => 1,
        'not_indexed' => 1,
        'chunks' => 3,
        'characters' => 1600,
        'average_chunk' => 533,
        'largest_chunk' => 700,
        'embedding_bytes' => 72,
        'unembedded' => 0,
        'open_gaps' => 1,
    ])
        ->and($stats['languages'])->toBe([
            'ar' => ['chunks' => 1, 'characters' => 700],
            'en' => ['chunks' => 2, 'characters' => 900],
        ])
        ->and(collect($stats['sources'])->firstWhere('title', 'Labor Law'))->toMatchArray([
            'kind' => 'pdf',
            'file_name' => 'labor-law.pdf',
            'page_count' => 68,
            'body_characters' => 1600,
            'chunks' => 3,
            'chunks_en' => 2,
            'chunks_ar' => 1,
            'characters_ar' => 700,
            'with_page' => 2,
            'searchable' => true,
        ])
        ->and(collect($stats['sources'])->firstWhere('title', 'VPN guide'))->toMatchArray(['kind' => 'written', 'chunks' => 0, 'searchable' => false])
        ->and($stats['imports'])->toMatchArray(['files' => 1, 'bytes' => 2048, 'pages' => 68, 'prompt_tokens' => 150, 'completion_tokens' => 59, 'failed' => 0])
        ->and($stats['storage'])->toBeNull();
});

it('sorts chunks into size bands by characters', function () {
    $article = statsArticle('Handbook');

    foreach ([['en', 250], ['en', 251], ['ar', 500], ['ar', 1000], ['en', 1500], ['en', 1501]] as [$locale, $length]) {
        statsChunk(['article_id' => $article, 'locale' => $locale, 'content' => str_repeat('x', $length)]);
    }

    expect(app(KnowledgeStats::class)->build()['sizes'])->toBe([
        ['label' => 'Up to 250', 'en' => 1, 'ar' => 0, 'other' => 0, 'total' => 1],
        ['label' => '251–500', 'en' => 1, 'ar' => 1, 'other' => 0, 'total' => 2],
        ['label' => '501–1,000', 'en' => 0, 'ar' => 1, 'other' => 0, 'total' => 1],
        ['label' => '1,001–1,500', 'en' => 1, 'ar' => 0, 'other' => 0, 'total' => 1],
        ['label' => 'Over 1,500', 'en' => 1, 'ar' => 0, 'other' => 0, 'total' => 1],
    ]);
});

it('lists an indexed Employee Documents PDF as a source of its own', function () {
    $document = DB::table('portal_documents')->insertGetId([
        'title' => 'AvePoint report', 'file_name' => 'avepoint.pdf', 'is_published' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    statsChunk(['portal_document_id' => $document, 'locale' => null, 'content' => str_repeat('x', 100)]);

    $stats = app(KnowledgeStats::class)->build();

    expect($stats['totals']['library_documents'])->toBe(1)
        ->and($stats['languages'])->toBe(['other' => ['chunks' => 1, 'characters' => 100]])
        ->and(collect($stats['sources'])->firstWhere('kind', 'library'))->toMatchArray(['title' => 'AvePoint report', 'chunks' => 1]);
});
