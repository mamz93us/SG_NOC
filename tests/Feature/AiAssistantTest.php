<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\NocTicket;
use App\Models\User;
use App\Support\HomePortal;
use Illuminate\Support\Facades\Http;

// Feature tests auto-use RefreshDatabase (see tests/Pest.php).
//
// NOTE: at the time of writing, the Feature suite errors under the SQLite
// :memory: connection configured in phpunit.xml — several historical
// migrations use MySQL-only `MODIFY COLUMN` DDL that SQLite rejects. These
// tests are written against the same conventions as the rest of the suite
// and are expected to pass once run against MySQL; see
// tests/Feature/Api/HrApiTest.php for the same limitation.

function homeUrl(string $path): string
{
    return 'https://'.HomePortal::domain().'/'.ltrim($path, '/');
}

function configureAi(array $overrides = []): AiSetting
{
    return AiSetting::create(array_merge([
        'enabled' => true,
        'azure_endpoint' => 'https://example.openai.azure.com',
        'azure_api_key' => 'test-key',
        'azure_api_version' => '2024-08-01-preview',
        'chat_deployment' => 'gpt-4o',
        'embedding_deployment' => 'text-embedding-3-small',
        'max_output_tokens' => 800,
        'temperature' => 0.2,
        'max_tool_turns' => 6,
        'daily_message_cap' => 60,
        'retention_days' => 180,
        'ticket_drafting_enabled' => true,
    ], $overrides));
}

function fakeChatReply(string $content, array $toolCalls = []): array
{
    $message = ['role' => 'assistant', 'content' => $content];
    if ($toolCalls) {
        $message['tool_calls'] = $toolCalls;
    }

    return [
        'choices' => [['message' => $message, 'finish_reason' => $toolCalls ? 'tool_calls' : 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}

it('does not let one employee read another employee\'s conversation', function () {
    configureAi();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $conversation = AiConversation::create(['user_id' => $owner->id, 'locale' => 'en']);

    $this->actingAs($intruder)
        ->get(homeUrl('assistant/'.$conversation->id))
        ->assertStatus(404);

    $this->actingAs($owner)
        ->get(homeUrl('assistant/'.$conversation->id))
        ->assertStatus(200);
});

it('does not let one employee rate a message in another employee\'s conversation', function () {
    configureAi();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $conversation = AiConversation::create(['user_id' => $owner->id, 'locale' => 'en']);
    $message = AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => AiMessage::ROLE_ASSISTANT,
        'content' => 'hello',
    ]);

    $this->actingAs($intruder)
        ->postJson(homeUrl('assistant/'.$message->id.'/rate'), ['rating' => 1])
        ->assertStatus(404);

    expect($message->fresh()->rating)->toBeNull();
});

it('rejects a draft_ticket tool call before search_knowledge has run, and creates no ticket', function () {
    configureAi();
    $user = User::factory()->create();

    Http::fake([
        '*/chat/completions*' => Http::sequence()
            // First turn: model tries to draft a ticket immediately.
            ->push(fakeChatReply(null, [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'draft_ticket', 'arguments' => json_encode([
                    'title' => 'Cannot print',
                    'description' => 'Printer offline',
                    'category_id' => 1,
                    'subcategory_id' => 1,
                    'reason_not_solved' => 'nothing checked',
                ])],
            ]]))
            // Second turn: model gives up after seeing the rejection.
            ->push(fakeChatReply('I could not find an answer.')),
    ]);

    $response = $this->actingAs($user)
        ->postJson(homeUrl('assistant/message'), ['message' => 'my printer is broken']);

    $response->assertStatus(200);
    expect($response->json('draft_ticket'))->toBeNull();
    expect(NocTicket::count())->toBe(0);

    $toolMessage = AiMessage::where('role', AiMessage::ROLE_TOOL)->first();
    expect($toolMessage)->not->toBeNull();
    expect($toolMessage->content)->toContain('search_knowledge first');
});

it('never creates a noc_tickets row from draft_ticket alone', function () {
    configureAi();
    $user = User::factory()->create();

    Http::fake([
        '*/chat/completions*' => Http::sequence()
            ->push(fakeChatReply(null, [[
                'id' => 'call_search',
                'type' => 'function',
                'function' => ['name' => 'search_knowledge', 'arguments' => json_encode(['query' => 'printer offline'])],
            ]]))
            ->push(fakeChatReply(null, [[
                'id' => 'call_draft',
                'type' => 'function',
                'function' => ['name' => 'draft_ticket', 'arguments' => json_encode([
                    'title' => 'Cannot print',
                    'description' => 'Printer offline after outage',
                    'category_id' => 1,
                    'subcategory_id' => 1,
                    'reason_not_solved' => 'Knowledge base had nothing on this printer model.',
                ])],
            ]]))
            ->push(fakeChatReply('Here is a draft ticket for you to review.')),
        '*/embeddings*' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 8, 0.1)]]]),
    ]);

    $response = $this->actingAs($user)
        ->postJson(homeUrl('assistant/message'), ['message' => 'my printer is broken and nothing helps']);

    $response->assertStatus(200);
    expect($response->json('draft_ticket.draft'))->toBeTrue();
    expect(NocTicket::count())->toBe(0); // draft_ticket only ever returns a draft
});

it('returns 429 once the daily message cap is reached', function () {
    configureAi(['daily_message_cap' => 1]);
    $user = User::factory()->create();

    $conversation = AiConversation::create(['user_id' => $user->id, 'locale' => 'en']);
    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => AiMessage::ROLE_USER,
        'content' => 'already sent today',
    ]);

    Http::fake(['*/chat/completions*' => Http::response(fakeChatReply('should not be reached'))]);

    $this->actingAs($user)
        ->postJson(homeUrl('assistant/message'), ['message' => 'one more question'])
        ->assertStatus(429);
});
