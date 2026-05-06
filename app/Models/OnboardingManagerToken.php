<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OnboardingManagerToken extends Model
{
    protected $fillable = [
        'token',
        'workflow_id',
        'manager_email',
        'manager_name',
        'laptop_status',
        'internet_level',
        'needs_extension',
        'floor_id',
        'selected_group_ids',
        'manager_comments',
        'responded_at',
        'reminded_at',
        'reminder_count',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'selected_group_ids' => 'array',
        'needs_extension'    => 'boolean',
        'responded_at'       => 'datetime',
        'reminded_at'        => 'datetime',
        'reminder_count'     => 'integer',
        'used_at'            => 'datetime',
        'expires_at'         => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public static function generate(int $workflowId, array $attributes = []): self
    {
        return static::create(array_merge([
            'token'      => Str::random(48),
            'workflow_id'=> $workflowId,
            'expires_at' => now()->addDays(14),
        ], $attributes));
    }

    public function isValid(): bool
    {
        return is_null($this->used_at)
            && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public function hasResponse(): bool
    {
        return ! is_null($this->responded_at);
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
