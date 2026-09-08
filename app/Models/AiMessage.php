<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id',
        'tokens_in', 'tokens_out', 'model', 'latency_ms', 'rating', 'rating_reason',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
        'rating' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** This row, in the shape the chat completions API expects. */
    public function toApiMessage(): array
    {
        $msg = ['role' => $this->role];

        if ($this->content !== null) {
            $msg['content'] = $this->content;
        }

        if ($this->role === self::ROLE_ASSISTANT && $this->tool_calls) {
            $msg['tool_calls'] = $this->tool_calls;
        }

        if ($this->role === self::ROLE_TOOL && $this->tool_call_id) {
            $msg['tool_call_id'] = $this->tool_call_id;
        }

        return $msg;
    }
}
