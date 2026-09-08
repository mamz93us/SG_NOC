<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport only, against the company's own Azure OpenAI tenancy.
 *
 *   POST {endpoint}/openai/deployments/{chat_deployment}/chat/completions?api-version=…
 *   POST {endpoint}/openai/deployments/{embedding_deployment}/embeddings?api-version=…
 *
 * Auth is the `api-key` header (not Bearer — that is Azure's own scheme, not
 * OpenAI's). No constructor: settings are read lazily on each call so a
 * settings-page edit takes effect on the next request without a container
 * rebind, matching every other *ApiService in this codebase.
 */
class AzureOpenAiClient
{
    private function settings(): AiSetting
    {
        return AiSetting::get();
    }

    /**
     * One chat completion turn.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $tools  OpenAI-style function tool definitions; empty = no tools offered
     * @return array{message: array<string,mixed>, usage: array<string,int>}
     *
     * @throws RuntimeException on any non-2xx, an unconfigured client, or a body that is not JSON
     */
    public function chat(array $messages, array $tools = [], array $opts = []): array
    {
        $settings = $this->settings();

        if (! $settings->isConfigured()) {
            throw new RuntimeException((string) $settings->configurationIssue());
        }

        $url = $this->url($settings, $settings->chat_deployment, 'chat/completions');

        $payload = [
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? $settings->max_output_tokens,
            'temperature' => $opts['temperature'] ?? $settings->temperature,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $opts['tool_choice'] ?? 'auto';
        }

        $response = Http::withHeaders([
            'api-key' => $settings->azure_api_key,
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'HTTP '.$response->status().' from '.$this->redact($url).': '
                .mb_substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['choices'][0]['message'])) {
            throw new RuntimeException(
                'Expected a chat completion from '.$this->redact($url).', got: '
                .mb_substr($response->body(), 0, 300)
            );
        }

        return [
            'message' => $body['choices'][0]['message'],
            'finish_reason' => $body['choices'][0]['finish_reason'] ?? null,
            'usage' => [
                'prompt_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($body['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Embeddings for one or more strings, in the same order as $texts.
     *
     * @param  array<int,string>  $texts
     * @return array<int,array<int,float>>
     *
     * @throws RuntimeException on any non-2xx, no embedding deployment configured, or a body that is not JSON
     */
    public function embed(array $texts): array
    {
        $settings = $this->settings();

        if (! $settings->embeddingsConfigured()) {
            throw new RuntimeException(
                $settings->configurationIssue() ?? 'No embedding deployment is configured.'
            );
        }

        if ($texts === []) {
            return [];
        }

        $url = $this->url($settings, $settings->embedding_deployment, 'embeddings');

        $response = Http::withHeaders([
            'api-key' => $settings->azure_api_key,
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->post($url, ['input' => array_values($texts)]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'HTTP '.$response->status().' from '.$this->redact($url).': '
                .mb_substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            throw new RuntimeException(
                'Expected embeddings from '.$this->redact($url).', got: '.mb_substr($response->body(), 0, 300)
            );
        }

        // The API returns items with an `index`, but not necessarily in
        // request order in every implementation — sort defensively so the
        // caller can zip the result back against $texts positionally.
        $rows = $body['data'];
        usort($rows, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(fn ($row) => array_map('floatval', $row['embedding'] ?? []), $rows);
    }

    /**
     * A minimal live call, for the Settings page's Test Connection button.
     *
     * @return array{ok: bool, detail: string}
     */
    public function testConnection(): array
    {
        $settings = $this->settings();

        if (! $settings->isConfigured()) {
            return ['ok' => false, 'detail' => (string) $settings->configurationIssue()];
        }

        try {
            $result = $this->chat(
                [['role' => 'user', 'content' => 'Reply with the single word: ok']],
                [],
                ['max_tokens' => 5, 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }

        $reply = trim((string) ($result['message']['content'] ?? ''));

        return ['ok' => true, 'detail' => 'Chat deployment responded: "'.mb_substr($reply, 0, 60).'"'];
    }

    private function url(AiSetting $settings, string $deployment, string $path): string
    {
        $endpoint = rtrim((string) $settings->azure_endpoint, '/');

        return "{$endpoint}/openai/deployments/".rawurlencode($deployment)."/{$path}?api-version=".
            rawurlencode((string) $settings->azure_api_version);
    }

    /** Strips the query string (api-version only, but keep the habit) from a URL before logging/throwing it. */
    private function redact(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
