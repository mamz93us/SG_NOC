<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiKnowledgeGap;
use App\Models\AiMessage;
use App\Models\AiSetting;
use Illuminate\Support\Collection;

/**
 * The assistant's turn loop: system prompt + history -> chat (with tools) ->
 * execute tool calls -> repeat up to max_tool_turns -> persist.
 *
 * The "troubleshoot before escalate" rule the owner asked for is enforced
 * here, not only in the prompt: a `draft_ticket` call is rejected — with the
 * rejection fed back to the model as a tool error — unless `search_knowledge`
 * has already run somewhere in this conversation. A prompt-only rule is a
 * suggestion; this is the one place that cannot be talked past.
 */
class AssistantAgent
{
    public function __construct(private AzureOpenAiClient $client) {}

    public function respond(AiConversation $conversation, string $userText, AssistantToolbox $toolbox): AiMessage
    {
        $settings = AiSetting::get();

        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $userText,
        ]);

        $history = $conversation->messages()->get();
        $hasSearched = $this->hasSearchedKnowledge($history);

        $apiMessages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($settings)]],
            $history->map(fn (AiMessage $m) => $m->toApiMessage())->all(),
        );

        $toolDefs = $toolbox->definitions();
        $lastAssistant = null;
        $maxTurns = max(1, $settings->max_tool_turns);

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $offeringTools = $turn < $maxTurns - 1; // last turn forces a plain answer
            $lastAssistant = $this->turn($conversation, $apiMessages, $offeringTools ? $toolDefs : []);
            $apiMessages[] = $lastAssistant->toApiMessage();

            $toolCalls = $lastAssistant->tool_calls;
            if (! $toolCalls) {
                break;
            }

            foreach ($toolCalls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $args = is_array($args) ? $args : [];

                if ($name === 'draft_ticket' && ! $hasSearched) {
                    $toolResult = [
                        'error' => 'You must call search_knowledge first, and it must fail to answer the question, before draft_ticket may be used.',
                    ];
                } else {
                    $toolResult = $toolbox->call($name, $args);

                    if ($name === 'search_knowledge') {
                        $hasSearched = true;

                        if (($toolResult['found'] ?? null) === false) {
                            AiKnowledgeGap::record((string) ($args['query'] ?? ''), $conversation->locale);
                        }
                    }
                }

                $toolRow = AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => AiMessage::ROLE_TOOL,
                    'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'tool_call_id' => $call['id'] ?? null,
                ]);

                $apiMessages[] = $toolRow->toApiMessage();
            }
        }

        if (! $conversation->title) {
            $conversation->title = mb_substr($userText, 0, 80);
        }
        $conversation->last_message_at = now();
        $conversation->message_count = $conversation->messages()->count();
        $conversation->save();

        return $lastAssistant;
    }

    /** One chat() call, persisted as an assistant AiMessage row. */
    private function turn(AiConversation $conversation, array $apiMessages, array $toolDefs): AiMessage
    {
        $settings = AiSetting::get();
        $started = microtime(true);

        $result = $this->client->chat($apiMessages, $toolDefs);

        $latencyMs = (int) ((microtime(true) - $started) * 1000);
        $message = $result['message'];

        $row = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
            'tokens_in' => $result['usage']['prompt_tokens'],
            'tokens_out' => $result['usage']['completion_tokens'],
            'model' => $settings->chat_deployment,
            'latency_ms' => $latencyMs,
        ]);

        $conversation->increment('total_tokens', $result['usage']['total_tokens']);

        return $row;
    }

    /** Whether search_knowledge has been called anywhere in this conversation so far. */
    private function hasSearchedKnowledge(Collection $history): bool
    {
        foreach ($history as $message) {
            foreach ($message->tool_calls ?? [] as $call) {
                if (($call['function']['name'] ?? null) === 'search_knowledge') {
                    return true;
                }
            }
        }

        return false;
    }

    private function systemPrompt(AiSetting $settings): string
    {
        $locale = app()->getLocale();
        $base = trans('home_ai.system_prompt', [], $locale);

        // Chat completions have no built-in notion of "now" — without this,
        // "schedule it for tomorrow at 3pm" (draft_calendar_event) has
        // nothing to resolve "tomorrow" against.
        $now = now('Africa/Cairo');
        $base .= "\n\nCurrent date and time: {$now->format('l, Y-m-d H:i')} (Africa/Cairo).";

        $extra = trim((string) $settings->system_prompt_extra);

        return $extra !== '' ? $base."\n\n".$extra : $base;
    }
}
