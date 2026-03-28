<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FormToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'form_id', 'token', 'label', 'email',
        'uses_limit', 'uses_count', 'expires_at',
    ];

    protected $casts = [
        'uses_limit'  => 'integer',
        'uses_count'  => 'integer',
        'expires_at'  => 'datetime',
        'created_at'  => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function form(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_id');
    }

    // ─── Factory ──────────────────────────────────────────────────

    public static function generate(int $formId, array $attributes = []): self
    {
        return static::create(array_merge([
            'form_id'    => $formId,
            'token'      => Str::random(48),
            'expires_at' => now()->addDays(30),
        ], $attributes));
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isValid(): bool
    {
        $notExpired  = is_null($this->expires_at) || $this->expires_at->isFuture();
        $notExhausted = is_null($this->uses_limit) || $this->uses_count < $this->uses_limit;
        return $notExpired && $notExhausted;
    }

    public function incrementUses(): void
    {
        $this->increment('uses_count');
    }
}
