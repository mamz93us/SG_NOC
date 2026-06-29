<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailSignatureTemplate extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'type',
        'logo_url',
        'primary_color',
        'html_body',
        'plain_text_body',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function typeLabel(): string
    {
        return match ($this->type) {
            'new_email' => 'New Email',
            'reply'     => 'Reply',
            'all'       => 'All',
            default     => $this->type,
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'new_email' => 'bg-primary',
            'reply'     => 'bg-info text-dark',
            'all'       => 'bg-secondary',
            default     => 'bg-secondary',
        };
    }

    /**
     * Pick the best matching active template for a given type + domain.
     *
     * Priority order (highest first):
     *   1. domain + type exact match
     *   2. domain + type = 'all'
     *   3. domain IS NULL + type match
     *   4. domain IS NULL + type = 'all'
     */
    public static function findBest(string $type, ?string $domain = null): ?self
    {
        return static::active()
            ->orderByRaw("
                CASE
                    WHEN domain = ? AND `type` = ? THEN 1
                    WHEN domain = ? AND `type` = 'all' THEN 2
                    WHEN domain IS NULL AND `type` = ? THEN 3
                    WHEN domain IS NULL AND `type` = 'all' THEN 4
                    ELSE 5
                END
            ", [$domain, $type, $domain, $type])
            ->orderBy('sort_order')
            ->first();
    }
}
