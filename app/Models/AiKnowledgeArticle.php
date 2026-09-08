<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admin-authored knowledge article the AI Assistant can search and cite.
 *
 * Audience targeting is the same coarse triple as PortalDocument (everyone /
 * one branch / one department) — see that model's scopeForEmployee, which
 * this mirrors exactly.
 */
class AiKnowledgeArticle extends Model
{
    protected $fillable = [
        'title', 'title_ar', 'body', 'body_ar',
        'category', 'tags',
        'audience', 'audience_branch_id', 'audience_department_id',
        'is_published', 'source_document_id', 'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'audience_branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'audience_department_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PortalDocument::class, 'source_document_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'article_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Same rule as PortalDocument::scopeForEmployee — a missing HR record still sees 'all'. */
    public function scopeForEmployee(Builder $query, ?Employee $employee): Builder
    {
        return $query->where(function (Builder $q) use ($employee) {
            $q->where('audience', 'all');

            if ($employee?->branch_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'branch')
                    ->where('audience_branch_id', $employee->branch_id));
            }

            if ($employee?->department_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'department')
                    ->where('audience_department_id', $employee->department_id));
            }
        });
    }

    /** Body/title in the reader's own language, falling back to English/Arabic. */
    public function titleFor(string $locale): string
    {
        return $locale === 'ar' && $this->title_ar ? $this->title_ar : $this->title;
    }

    public function bodyFor(string $locale): string
    {
        return $locale === 'ar' && $this->body_ar ? $this->body_ar : $this->body;
    }
}
