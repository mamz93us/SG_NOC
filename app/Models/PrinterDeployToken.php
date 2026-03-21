<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterDeployToken extends Model
{
    protected $fillable = [
        'employee_id',
        'branch_id',
        'token',
        'expires_at',
        'used_at',
        'sent_to_email',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeValid(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now())
                 ->whereNull('used_at');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return ! is_null($this->used_at);
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
