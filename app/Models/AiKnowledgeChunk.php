<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrieval unit — a heading-sized slice of an article — plus its packed
 * embedding. See the migration docblock for why this is a blob and not a
 * vector column.
 */
class AiKnowledgeChunk extends Model
{
    protected $fillable = [
        'article_id', 'portal_document_id', 'heading', 'content', 'content_hash', 'locale',
        'embedding', 'token_count', 'audience', 'audience_branch_id', 'audience_department_id',
    ];

    protected $casts = [
        'token_count' => 'integer',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }

    public function portalDocument(): BelongsTo
    {
        return $this->belongsTo(PortalDocument::class, 'portal_document_id');
    }

    /** @param  array<int,float>  $vector */
    public function setEmbeddingVector(array $vector): void
    {
        $this->embedding = pack('f*', ...$vector);
    }

    /** @return array<int,float> */
    public function embeddingVector(): array
    {
        if (! $this->embedding) {
            return [];
        }

        return array_values(unpack('f*', $this->embedding) ?: []);
    }

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
}
