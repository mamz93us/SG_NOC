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
        'gender',
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
    public static function findBest(string $type, ?string $domain = null, ?string $gender = null): ?self
    {
        return static::active()
            // When a gender is known, exclude the OPPOSITE gender (keep this gender +
            // gender-neutral). When gender is unknown (e.g. the server transport-rule
            // render), apply no gender filter so domain still wins — never cross domains
            // to a gender-neutral template of the wrong company.
            ->when($gender !== null, function ($q) use ($gender) {
                $q->where(fn ($w) => $w->where('gender', $gender)
                    ->orWhere('gender', 'all')
                    ->orWhereNull('gender'));
            })
            // Primary: right domain + slot. A SAME-domain template of any type (tier 3)
            // always beats a different-domain one — so e.g. a samirgroup reply falls back
            // to the samirgroup new-mail template, never to an SSS template.
            ->orderByRaw("
                CASE
                    WHEN domain = ? AND `type` = ? THEN 1
                    WHEN domain = ? AND `type` = 'all' THEN 2
                    WHEN domain = ? THEN 3
                    WHEN domain IS NULL AND `type` = ? THEN 4
                    WHEN domain IS NULL AND `type` = 'all' THEN 5
                    WHEN domain IS NULL THEN 6
                    ELSE 7
                END
            ", [$domain, $type, $domain, $domain, $type])
            // Tiebreak: prefer the template matching this gender (harmless for known
            // genders; wins the tie so a female user gets the female template, etc.).
            ->orderByRaw('CASE WHEN gender = ? THEN 0 ELSE 1 END', [$gender ?? '__none__'])
            // When gender is unknown, default to male (org majority) > neutral > female,
            // so the ~100 gender-less samirgroup users get the male variant, not female.
            ->orderByRaw("CASE `gender` WHEN 'male' THEN 0 WHEN 'all' THEN 1 WHEN 'female' THEN 2 ELSE 3 END")
            ->orderBy('sort_order')
            ->first();
    }
}
