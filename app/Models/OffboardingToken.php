<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OffboardingToken extends Model
{
    protected $fillable = [
        'token',
        'workflow_id',
        'employee_id',
        'manager_email',
        'manager_name',
        'payload',
        'manager_notes',
        'manager_decision',
        'responded_at',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'responded_at' => 'datetime',
        'used_at'      => 'datetime',
        'expires_at'   => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class, 'workflow_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public static function generate(int $workflowId, array $attributes = []): self
    {
        return static::create(array_merge([
            'token'      => Str::random(48),
            'workflow_id'=> $workflowId,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function isValid(): bool
    {
        return is_null($this->used_at)
            && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
