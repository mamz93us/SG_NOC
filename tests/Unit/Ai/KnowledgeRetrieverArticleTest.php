<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use App\Models\AiSetting;
use App\Services\Ai\KnowledgeRetriever;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Asked for "المادة 77", the assistant was given Articles 73, 105 and 118 and
 * told the employee Article 77 was not in the law: embeddings score one
 * article number as close as any other. A question naming an article now gets
 * that article. The question embeds as [1, 0, 0], so every score is known.
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
        'knowledge_match_threshold' => 0.40,
    ]);

    Http::fake(['*/embeddings*' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]]])]);

    $law = AiKnowledgeArticle::create([
        'title' => 'Labor Law', 'body' => 'x', 'category' => 'HR Policies', 'tags' => ['labor law'],
        'audience' => 'all', 'is_published' => true,
    ]);

    foreach ([
        ['ar', 'المادة الثالثة والسبعون:', 'يجب على صاحب العمل أن يكتب الغرامات.', [0.9, 0.1, 0.0]],
        ['ar', 'المادة السابعة والسبعون:', 'ما لم يتضمن العقد تعويضًا محددًا.', [0.0, 0.0, 1.0]],
        ['en', 'Article 77:', 'Unless the contract specifies a defined compensation.', [0.0, 1.0, 0.0]],
        ['ar', 'المادة السابعة والسبعون بعد المائة:', 'يستحق البحار أجره.', [0.8, 0.2, 0.0]],
    ] as [$locale, $heading, $content, $vector]) {
        $chunk = new AiKnowledgeChunk([
            'article_id' => $law->id, 'heading' => $heading, 'content' => $content,
            'content_hash' => hash('sha256', $locale.$heading.$content), 'locale' => $locale,
            'token_count' => 10, 'audience' => 'all',
        ]);
        $chunk->setEmbeddingVector($vector);
        $chunk->save();
    }
});

it('puts the article a question names first, however far its embedding is from the question', function () {
    expect(app(KnowledgeRetriever::class)->search('المادة 77', null)->pluck('heading')->all())->toBe([
        'المادة السابعة والسبعون:', // the article, in the question's language first
        'Article 77:',
        'المادة الثالثة والسبعون:', // then what the embeddings rank above the floor
        'المادة السابعة والسبعون بعد المائة:',
    ]);
});

it('does not take an article with a similar number for the one asked for', function () {
    $headings = app(KnowledgeRetriever::class)->search('Article 177', null)->pluck('heading');

    expect($headings->first())->toBe('المادة السابعة والسبعون بعد المائة:')
        ->and($headings)->not->toContain('Article 77:')
        ->and($headings)->not->toContain('المادة السابعة والسبعون:');
});
