<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeGap;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Services\Ai\KnowledgeGapService;
use App\Services\Ai\KnowledgeIndexer;
use App\Services\Ai\TextTranslator;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Teaching the assistant what it could not answer: questions are caught,
 * grouped by what they ask, answered by a person or found covered by an
 * article published since, and closed. Vectors have three numbers, so every
 * similarity here is known exactly.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach ([
        'ai_knowledge_imports', 'ai_knowledge_gaps', 'ai_knowledge_chunks', 'ai_knowledge_articles',
        'ai_messages', 'ai_conversations', 'portal_documents', 'employees', 'users', 'ai_settings',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    // Parents the AI tables' foreign keys name, with only what these tests touch.
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('email')->nullable();
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
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
        '2026_09_08_100005_create_ai_conversations_table',
        '2026_09_08_100006_create_ai_messages_table',
        '2026_09_08_100007_create_ai_knowledge_gaps_table',
        '2026_09_09_120000_add_locale_to_ai_knowledge_chunks_table',
        '2026_09_14_110001_create_ai_knowledge_imports_table',
        '2026_09_14_120001_add_source_to_ai_knowledge_chunks_table',
        '2026_09_14_130001_extend_ai_knowledge_gaps_for_answers',
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

function gapWith(string $question, array $vector, array $attributes = []): AiKnowledgeGap
{
    $gap = AiKnowledgeGap::record($question);
    $gap->forceFill(array_merge(['embedding' => pack('f*', ...$vector)], $attributes))->save();

    return $gap->refresh();
}

function gapArticle(string $title, array $vector, bool $published = true, ?Carbon $updatedAt = null): AiKnowledgeArticle
{
    $article = AiKnowledgeArticle::create(['title' => $title, 'body' => $title, 'audience' => 'all', 'is_published' => $published]);

    DB::table('ai_knowledge_chunks')->insert([
        'article_id' => $article->id,
        'content' => $title,
        'content_hash' => hash('sha256', $title.microtime()),
        'locale' => 'en',
        'embedding' => pack('f*', ...$vector),
        'audience' => 'all',
        'created_at' => $updatedAt ?? now(),
        'updated_at' => $updatedAt ?? now(),
    ]);

    return $article;
}

it('records a question once, counts every ask, and files it under the language it is in', function () {
    AiKnowledgeGap::record('How do I claim overtime?');
    AiKnowledgeGap::record('  how do I claim   overtime? ');
    AiKnowledgeGap::record('كيف أطلب إجازة؟');

    expect(AiKnowledgeGap::count())->toBe(2)
        ->and(AiKnowledgeGap::where('locale', 'en')->first())->toMatchArray(['hit_count' => 2, 'source' => AiKnowledgeGap::NO_RESULTS])
        ->and(AiKnowledgeGap::where('locale', 'ar')->value('query_sample'))->toBe('كيف أطلب إجازة؟');
});

it('opens an answered question again when it is asked again, but leaves a dismissed one dismissed', function () {
    $answered = AiKnowledgeGap::record('What is the notice period?');
    $answered->resolve(AiKnowledgeGap::ANSWERED, 5, 1);
    $dismissed = AiKnowledgeGap::record('Who won the match yesterday?');
    $dismissed->resolve(AiKnowledgeGap::DISMISSED, null, 1);

    AiKnowledgeGap::record('What is the notice period?', AiKnowledgeGap::NOT_HELPFUL);
    AiKnowledgeGap::record('Who won the match yesterday?');

    expect($answered->refresh()->resolved_at)->toBeNull()
        ->and($answered->article_id)->toBeNull()
        ->and($answered->source)->toBe(AiKnowledgeGap::NOT_HELPFUL)
        ->and($dismissed->refresh()->resolution)->toBe(AiKnowledgeGap::DISMISSED)
        ->and($dismissed->hit_count)->toBe(2);
});

it('lists a reply rated not helpful only when it answered from a knowledge search', function () {
    DB::table('users')->insert(['id' => 1, 'name' => 'Mona Adel']);
    $conversation = DB::table('ai_conversations')->insertGetId(['user_id' => 1, 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()]);

    $message = fn (array $attributes) => AiMessage::create(['conversation_id' => $conversation] + $attributes);

    $message(['role' => 'user', 'content' => 'What is the notice period if I resign?']);
    $message(['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_knowledge', 'arguments' => '{}']]]]);
    $message(['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"found":true}']);
    $fromSearch = $message(['role' => 'assistant', 'content' => 'Thirty days.']);

    $message(['role' => 'user', 'content' => 'What time did I check in today?']);
    $message(['role' => 'assistant', 'tool_calls' => [['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'get_my_attendance', 'arguments' => '{}']]]]);
    $message(['role' => 'tool', 'tool_call_id' => 'c2', 'content' => '{}']);
    $fromAttendance = $message(['role' => 'assistant', 'content' => 'At 08:57.']);

    expect(AiKnowledgeGap::recordNotHelpful($fromAttendance))->toBeNull()
        ->and(AiKnowledgeGap::recordNotHelpful($fromSearch))->not->toBeNull()
        ->and(AiKnowledgeGap::sole())->toMatchArray([
            'query_sample' => 'What is the notice period if I resign?',
            'source' => AiKnowledgeGap::NOT_HELPFUL,
            'last_conversation_id' => $conversation,
        ]);
});

it('groups the wordings of one question under the most asked', function () {
    $usb = gapWith('USB drive usage policy', [1, 0, 0], ['hit_count' => 3]);
    $flash = gapWith('using flash drive on company device', [0.95, 0.31, 0]);
    $vacation = gapWith('how to apply for vacation', [0, 1, 0], ['hit_count' => 2]);

    $questions = app(KnowledgeGapService::class)->regroup(AiKnowledgeGap::open()->get());

    expect($questions)->toBe(2)
        ->and($usb->refresh()->group_id)->toBe($usb->id)
        ->and($flash->refresh()->group_id)->toBe($usb->id)
        ->and($vacation->refresh()->group_id)->toBeNull();
});

it('closes a question an article now answers, and only offers one that is merely close', function () {
    $media = gapArticle('Removable media', [1, 0, 0]);
    gapArticle('Leave (draft)', [0, 1, 0], published: false);

    $usb = gapWith('Can I use a USB drive?', [1, 0, 0]);
    $leave = gapWith('How do I apply for leave?', [0.5, 0.8660254, 0]);

    $result = app(KnowledgeGapService::class)->recheck(AiKnowledgeGap::open()->get());

    expect($result)->toBe(['checked' => 2, 'closed' => 1])
        ->and($usb->refresh())->toMatchArray(['resolution' => AiKnowledgeGap::COVERED, 'article_id' => $media->id])
        ->and($leave->refresh()->resolved_at)->toBeNull()
        ->and($leave->best_score)->toBe(0.5) // the unpublished article would have scored 0.87
        ->and($leave->best_article_id)->toBe($media->id);
});

it('compares a question only with chunks written since it was last checked', function () {
    Carbon::setTestNow('2026-09-14 12:00:00');
    gapArticle('Old article', [1, 0, 0], updatedAt: now()->subHour());
    $gap = gapWith('Can I use a USB drive?', [1, 0, 0], ['checked_at' => now()->subMinutes(30), 'best_score' => 0.2]);

    app(KnowledgeGapService::class)->recheck(AiKnowledgeGap::open()->get());
    expect($gap->refresh()->resolved_at)->toBeNull()->and($gap->best_score)->toBe(0.2);

    Carbon::setTestNow('2026-09-14 12:15:00');
    $new = gapArticle('Removable media', [1, 0, 0]);
    app(KnowledgeGapService::class)->recheck(AiKnowledgeGap::open()->get());

    expect($gap->refresh()->article_id)->toBe($new->id);
    Carbon::setTestNow();
});

it('opens a question again when the article that closed it is unpublished', function () {
    $article = gapArticle('Removable media', [1, 0, 0]);
    $gap = gapWith('Can I use a USB drive?', [1, 0, 0]);
    $gap->resolve(AiKnowledgeGap::COVERED, $article->id);

    $article->update(['is_published' => false]);

    expect(app(KnowledgeGapService::class)->reopenOrphans())->toBe(1)
        ->and($gap->refresh()->resolved_at)->toBeNull()
        ->and($gap->best_article_id)->toBeNull();
});

it('publishes an answer, closes every wording, and scores each against it', function () {
    Http::fake(['*/embeddings*' => fn (Request $request) => Http::response([
        'data' => array_map(fn ($i) => ['index' => $i, 'embedding' => [1, 0, 0]], array_keys($request['input'])),
    ])]);

    $policy = gapWith('security policy', [1, 0, 0], ['hit_count' => 2]);
    $rules = gapWith('information security rules', [0.6, 0.8, 0]);

    $result = app(KnowledgeGapService::class)->answer(AiKnowledgeGap::open()->get(), [
        'title' => 'Information Security Policy',
        'body' => "## Removable media\nUSB drives are not allowed on company laptops.",
        'audience' => 'all',
    ], 7, app(KnowledgeIndexer::class));

    expect($result['article']->is_published)->toBeTrue()
        ->and($result['indexed'])->toBeTrue()
        ->and($result['article']->chunks()->count())->toBe(1)
        ->and($result['scores'])->toBe([
            ['question' => 'security policy', 'score' => 1.0],
            ['question' => 'information security rules', 'score' => 0.6],
        ])
        ->and($policy->refresh())->toMatchArray(['resolution' => AiKnowledgeGap::ANSWERED, 'article_id' => $result['article']->id, 'resolved_by' => 7])
        ->and($rules->refresh()->resolution)->toBe(AiKnowledgeGap::ANSWERED);
});

it('embeds, groups and checks new questions on its scheduled run', function () {
    Http::fake(['*/embeddings*' => fn (Request $request) => Http::response([
        'data' => array_map(fn ($i) => ['index' => $i, 'embedding' => [1, 0, 0]], array_keys($request['input'])),
    ])]);
    $article = gapArticle('Removable media', [1, 0, 0]);
    AiKnowledgeGap::record('Can I use a USB drive?');

    $this->artisan('ai:knowledge-gaps')->assertSuccessful();

    expect(AiKnowledgeGap::sole())->toMatchArray(['resolution' => AiKnowledgeGap::COVERED, 'article_id' => $article->id]);
});

it('offers the assistant a way to report what the results did not answer', function () {
    $toolbox = new App\Services\Ai\AssistantToolbox(
        new App\Models\User(['name' => 'Mona Adel', 'email' => 'mona.adel@samirgroup.com']), null, null,
        app(App\Services\Ai\KnowledgeRetriever::class), app(App\Services\Ticketing\TicketRequestService::class),
    );

    expect(collect($toolbox->definitions())->pluck('function.name'))->toContain('report_knowledge_gap')
        ->and($toolbox->call('report_knowledge_gap', ['question' => 'How do I claim overtime?']))->toHaveKey('recorded', true);
});

it('cuts text for translation at paragraphs, and inside a long paragraph at sentences', function () {
    $paragraph = str_repeat('A sentence of policy text. ', 10); // 270 characters
    $sections = TextTranslator::sections("{$paragraph}\n\n{$paragraph}\n\n".str_repeat('Long one. ', 60), 300);

    expect($sections)->each->toMatch('/^.{1,300}$/su')
        ->and(implode(' ', $sections))->toContain('A sentence of policy text.')
        ->and(TextTranslator::language('يستحق الموظف إجازة سنوية (annual leave).'))->toBe('ar')
        ->and(TextTranslator::language('Employees are entitled to annual leave (إجازة).'))->toBe('en');
});

it('translates through the chat deployment and refuses a translation that was cut off', function () {
    Http::fake(['*/chat/completions*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => 'يستحق الموظف إجازة سنوية.'], 'finish_reason' => 'stop']], 'usage' => []])
        ->push(['choices' => [['message' => ['content' => 'يستحق'], 'finish_reason' => 'length']], 'usage' => []])]);

    $translator = app(TextTranslator::class);

    expect($translator->translate('Employees are entitled to annual leave.', 'ar'))->toBe('يستحق الموظف إجازة سنوية.')
        ->and(fn () => $translator->translate('Employees are entitled to annual leave.', 'ar'))->toThrow(RuntimeException::class, 'cut off');
});
