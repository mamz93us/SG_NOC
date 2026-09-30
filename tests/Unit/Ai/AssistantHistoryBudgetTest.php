<?php

use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AzureOpenAiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * What a long conversation costs per call, and what happens when Azure says
 * "too many tokens". On 2026-09-30 an attendance owner's day-long chat sent
 * all 126 messages — a 69,000-character company attendance list among them —
 * on every call, 54,000 tokens each, to a deployment allowing 100,000 tokens
 * a minute. Two quick questions and the third was "not available".
 */
uses(Tests\TestCase::class);

/** @return list<array<string, mixed>> what the model would be sent for $history */
function modelHistory(array $history): array
{
    return (new ReflectionMethod(AssistantAgent::class, 'historyForModel'))
        ->invoke(new AssistantAgent(new AzureOpenAiClient), collect($history));
}

/** One question: the user's text, a tool call with a result of $resultChars, and an answer. */
function historyQuestion(int $n, int $resultChars = 100): array
{
    return [
        new AiMessage(['role' => AiMessage::ROLE_USER, 'content' => "question {$n}"]),
        new AiMessage(['role' => AiMessage::ROLE_ASSISTANT, 'tool_calls' => [['id' => "call_{$n}", 'type' => 'function', 'function' => ['name' => 'get_team_attendance', 'arguments' => '{}']]]]),
        new AiMessage(['role' => AiMessage::ROLE_TOOL, 'tool_call_id' => "call_{$n}", 'content' => str_repeat('x', $resultChars)]),
        new AiMessage(['role' => AiMessage::ROLE_ASSISTANT, 'content' => "answer {$n}"]),
    ];
}

test('only the latest ten questions are sent, starting at a question', function () {
    $history = [];
    foreach (range(1, 14) as $n) {
        array_push($history, ...historyQuestion($n));
    }
    $history[] = new AiMessage(['role' => AiMessage::ROLE_USER, 'content' => 'question 15']);

    $sent = modelHistory($history);

    // A tool result sent without the call it answers is an HTTP 400 from Azure.
    expect($sent[0])->toBe(['role' => 'user', 'content' => 'question 6']);
    expect(end($sent))->toBe(['role' => 'user', 'content' => 'question 15']);
    expect(collect($sent)->where('role', 'user')->count())->toBe(10);
});

test('a long tool result from an earlier question is replaced by a note, keeping its call id', function () {
    $history = [...historyQuestion(1, 69000), ...historyQuestion(2, 200)];
    $history[] = new AiMessage(['role' => AiMessage::ROLE_USER, 'content' => 'question 3']);

    $sent = modelHistory($history);

    expect($sent[2]['tool_call_id'])->toBe('call_1');
    expect($sent[2]['content'])->toContain('69000 characters')->toContain('call the tool again');
    // Short earlier results stay as they were.
    expect($sent[6]['content'])->toBe(str_repeat('x', 200));
});

test('the current question keeps its own tool results whole', function () {
    $history = [
        new AiMessage(['role' => AiMessage::ROLE_USER, 'content' => 'who is absent today?']),
        new AiMessage(['role' => AiMessage::ROLE_ASSISTANT, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_team_attendance', 'arguments' => '{}']]]]),
        new AiMessage(['role' => AiMessage::ROLE_TOOL, 'tool_call_id' => 'call_1', 'content' => str_repeat('y', 50000)]),
    ];

    expect(modelHistory($history)[2]['content'])->toBe(str_repeat('y', 50000));
});

// ─── HTTP 429 ────────────────────────────────────────────────────

function throttleSettings(): void
{
    Schema::dropIfExists('ai_settings');

    foreach (glob(database_path('migrations/*_ai_settings_table.php')) as $migration) {
        (require $migration)->up();
    }

    AiSetting::create([
        'enabled' => true,
        'azure_endpoint' => 'https://azure.test',
        'azure_api_key' => 'test-key',
        'chat_deployment' => 'gpt-4o',
    ]);
}

function chatReply(): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6]];
}

test('a chat waits out HTTP 429 when asked to, for as long as Azure says', function () {
    throttleSettings();
    Sleep::fake();
    Http::fakeSequence()
        ->push(['error' => ['code' => '429']], 429, ['retry-after' => '7'])
        ->push(chatReply());

    $result = (new AzureOpenAiClient)->chat([['role' => 'user', 'content' => 'hi']], [], ['wait_when_throttled' => 25]);

    expect($result['message']['content'])->toBe('OK');
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

test('without a wait, or past it, HTTP 429 is thrown as before', function () {
    throttleSettings();
    Sleep::fake();
    Http::fake(['*' => Http::response(['error' => ['code' => '429']], 429, ['retry-after' => '40'])]);

    expect(fn () => (new AzureOpenAiClient)->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(RuntimeException::class, 'HTTP 429');
    expect(fn () => (new AzureOpenAiClient)->chat([['role' => 'user', 'content' => 'hi']], [], ['wait_when_throttled' => 25]))
        ->toThrow(RuntimeException::class, 'HTTP 429');
    Sleep::assertNeverSlept();
});
