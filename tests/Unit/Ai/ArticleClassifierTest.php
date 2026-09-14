<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiSetting;
use App\Services\Ai\ArticleClassifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Category and tags by AI. Azure is faked: under test are what the model is
 * shown, what of its reply is kept, and which articles are filed, and how.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['ai_knowledge_articles', 'portal_documents', 'ai_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    // Only as the parent the articles table's foreign key names.
    Schema::create('portal_documents', function (Blueprint $t) {
        $t->id();
        $t->timestamps();
    });

    foreach ([
        '2026_09_08_100001_create_ai_settings_table',
        '2026_09_09_100000_add_reindex_tracking_to_ai_settings_table',
        '2026_09_09_100100_add_knowledge_match_threshold_to_ai_settings_table',
        '2026_09_08_100003_create_ai_knowledge_articles_table',
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

function filingReply(array $reply): void
{
    Http::fake(['*/chat/completions*' => Http::response([
        'choices' => [['message' => ['content' => json_encode($reply)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30],
    ])]);
}

function filingArticle(array $attributes = []): AiKnowledgeArticle
{
    return AiKnowledgeArticle::create(array_merge([
        'title' => 'Annual leave',
        'body' => "## Entitlement\nEmployees get 21 days a year.\n\n## Carrying over\nUp to 5 days carry over.",
        'audience' => 'all',
        'is_published' => true,
    ], $attributes));
}

it('keeps a tidy category and tags from the reply', function () {
    $filed = ArticleClassifier::parse(json_encode([
        'category' => '  "HR   Policies" ',
        'tags' => ['Annual Leave', '#carry-over', 'annual leave', 'hr policies', 'leave, vacation', 42, '', 'one', 'two', 'three', 'four', 'five', 'six'],
    ]));

    expect($filed)->toBe([
        'category' => 'HR Policies',
        'tags' => ['annual leave', 'carry-over', 'leave vacation', 'one', 'two', 'three', 'four', 'five'],
    ])
        ->and(fn () => ArticleClassifier::parse('{"category": "", "tags": ["vpn"]}'))->toThrow(RuntimeException::class, 'no category')
        ->and(fn () => ArticleClassifier::parse('{"category": "IT Support", "tags": []}'))->toThrow(RuntimeException::class, 'no tags')
        ->and(fn () => ArticleClassifier::parse('IT Support'))->toThrow(RuntimeException::class, 'did not reply');
});

it('shows the model the categories in use and the headings of the whole article, and asks for JSON', function () {
    filingArticle(['title' => 'VPN', 'category' => 'IT Support', 'tags' => ['vpn']]);
    filingArticle(['title' => 'Payslips', 'category' => 'Payroll', 'tags' => ['payslip']]);
    filingArticle(['title' => 'Printers', 'category' => 'IT Support', 'tags' => ['printer']]);
    filingReply(['category' => 'HR Policies', 'tags' => ['annual leave', 'carry-over']]);

    $body = "## Entitlement\n".str_repeat('Employees get 21 days a year. ', 300)."\n\n## Carrying over\nUp to 5 days carry over.";

    expect(app(ArticleClassifier::class)->classify('Annual leave', 'الإجازة السنوية', $body))
        ->toBe(['category' => 'HR Policies', 'tags' => ['annual leave', 'carry-over']]);

    Http::assertSent(function (Request $request) {
        $prompt = $request['messages'][1]['content'];

        return $request['response_format'] === ['type' => 'json_object']
            && str_contains($prompt, 'Existing categories: IT Support, Payroll')
            && str_contains($prompt, "Headings:\n- Entitlement\n- Carrying over")
            && str_contains($prompt, 'Arabic title: الإجازة السنوية')
            && ! str_contains($prompt, 'Up to 5 days carry over'); // beyond the part of the body that is read
    });
});

it('queues a new article without a category or tags, and leaves a filed one alone', function () {
    expect(filingArticle()->ai_classify)->toBe(AiKnowledgeArticle::CLASSIFY_MISSING)
        ->and(filingArticle(['category' => 'HR Policies'])->ai_classify)->toBe(AiKnowledgeArticle::CLASSIFY_MISSING)
        ->and(filingArticle(['category' => 'HR Policies', 'tags' => ['leave']])->ai_classify)->toBeNull();
});

it('fills in only what is missing, or chooses both again when asked to redo', function () {
    filingReply(['category' => 'HR Policies', 'tags' => ['annual leave', 'carry-over']]);

    $typed = filingArticle(['title' => 'Category typed by hand', 'category' => 'Leave']);
    $redo = filingArticle(['title' => 'Redo', 'category' => 'FAQ', 'tags' => ['old']]);
    $redo->forceFill(['ai_classify' => AiKnowledgeArticle::CLASSIFY_REPLACE])->save();
    $filled = filingArticle(['title' => 'Filled in since it was queued', 'category' => 'Payroll', 'tags' => ['payslip']]);
    $filled->forceFill(['ai_classify' => AiKnowledgeArticle::CLASSIFY_MISSING])->save();

    Artisan::call('ai:classify-articles', ['--max-seconds' => 30]);

    expect($typed->fresh())->toMatchArray(['category' => 'Leave', 'tags' => ['annual leave', 'carry-over'], 'ai_classify' => null])
        ->and($typed->fresh()->ai_classified_at)->not->toBeNull()
        ->and($redo->fresh())->toMatchArray(['category' => 'HR Policies', 'tags' => ['annual leave', 'carry-over'], 'ai_classify' => null])
        ->and($filled->fresh())->toMatchArray(['category' => 'Payroll', 'tags' => ['payslip'], 'ai_classify' => null]);

    Http::assertSentCount(2); // nothing to ask about the article filled in since
});

it('leaves an article queued while Azure is throttling, and writes a reply it cannot use on the article', function () {
    $article = filingArticle();

    Http::fake(['*/chat/completions*' => Http::sequence()
        ->push('Too many requests', 429)
        ->push(['choices' => [['message' => ['content' => '{"category": "HR Policies", "tags": []}'], 'finish_reason' => 'stop']], 'usage' => []])]);

    Artisan::call('ai:classify-articles', ['--max-seconds' => 30]);

    expect($article->fresh())->toMatchArray(['ai_classify' => AiKnowledgeArticle::CLASSIFY_MISSING, 'ai_classify_error' => null]);

    Artisan::call('ai:classify-articles', ['--max-seconds' => 30]);

    expect($article->fresh())->toMatchArray(['ai_classify' => null, 'ai_classify_error' => 'The AI gave no tags.', 'category' => null]);
});
