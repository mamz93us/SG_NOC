<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Config for the home portal's AI IT Assistant. Same singleton + encrypted-key
 * pattern as {@see Setting}, split into its own table because `settings` has
 * no room left (see the exchange_rates migration).
 */
class AiSetting extends Model
{
    protected $table = 'ai_settings';

    protected $fillable = [
        'enabled',
        'azure_endpoint',
        'azure_api_key',
        'azure_api_version',
        'chat_deployment',
        'embedding_deployment',
        'knowledge_match_threshold',
        'max_output_tokens',
        'temperature',
        'max_tool_turns',
        'daily_message_cap',
        'retention_days',
        'system_prompt_extra',
        'ticket_drafting_enabled',
        'last_reindex_started_at',
        'last_reindex_finished_at',
        'last_reindex_result',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'knowledge_match_threshold' => 'float',
        'max_output_tokens' => 'integer',
        'temperature' => 'float',
        'max_tool_turns' => 'integer',
        'daily_message_cap' => 'integer',
        'retention_days' => 'integer',
        'ticket_drafting_enabled' => 'boolean',
        'last_reindex_started_at' => 'datetime',
        'last_reindex_finished_at' => 'datetime',
        'last_reindex_result' => 'array',
    ];

    /** Still running (or never finished — e.g. the worker died) since the last dispatch. */
    public function reindexRunning(): bool
    {
        if (! $this->last_reindex_started_at) {
            return false;
        }

        return ! $this->last_reindex_finished_at
            || $this->last_reindex_finished_at->lt($this->last_reindex_started_at);
    }

    public static function get(): static
    {
        return static::first() ?? static::create([
            'enabled' => false,
            'azure_api_version' => '2024-08-01-preview',
            'knowledge_match_threshold' => 0.40,
            'max_output_tokens' => 800,
            'temperature' => 0.20,
            'max_tool_turns' => 6,
            'daily_message_cap' => 60,
            'retention_days' => 180,
            'ticket_drafting_enabled' => true,
        ]);
    }

    // ─── Azure API key — encrypted at rest ────────────────────────

    public function setAzureApiKeyAttribute(?string $value): void
    {
        $this->attributes['azure_api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAzureApiKeyAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Can the assistant actually talk to Azure OpenAI right now?
     *
     * Gates the chat endpoint and drives the settings page's status pill —
     * a half-configured integration must fail fast with a sentence, not a
     * generic 500 from deep inside a completion call.
     */
    public function isConfigured(): bool
    {
        return $this->configurationIssue() === null;
    }

    /** Why the assistant cannot run, in a sentence, or null when it can. */
    public function configurationIssue(): ?string
    {
        if (! $this->enabled) {
            return 'The AI Assistant is switched off in Admin → Settings.';
        }

        if (blank($this->azure_endpoint)) {
            return 'No Azure OpenAI endpoint is configured.';
        }

        if (blank($this->azure_api_key)) {
            return 'No Azure OpenAI API key is configured.';
        }

        if (blank($this->chat_deployment)) {
            return 'No chat deployment name is configured.';
        }

        return null;
    }

    /** Whether embeddings can be produced (search_knowledge needs this too). */
    public function embeddingsConfigured(): bool
    {
        return $this->isConfigured() && filled($this->embedding_deployment);
    }
}
