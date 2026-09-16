<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A decision to spend money reading documents.
 *
 * Two kinds, and they are the same shape because they are the same act:
 *
 *   read — read an archive's pages so its words become searchable
 *   fill — read them and propose values for fields that are empty
 *
 * Both exist as a row rather than a button because both are "spend an
 * unbounded amount across an unbounded number of documents". A batch is
 * estimated before it starts, its cost accumulates while it runs, and it stops
 * itself at the budget rather than finding out afterwards.
 *
 * Excluded from the automatic audit — its counters are rewritten every minute.
 * Starting, pausing and cancelling one are logged by hand, because those are
 * the decisions.
 */
class ArchiveAiBatch extends Model
{
    public const TYPE_READ = 'read';

    public const TYPE_FILL = 'fill';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Stopped because the month's budget ran out, not because anything broke. */
    public const STATUS_OVER_BUDGET = 'over_budget';

    protected $fillable = [
        'archive_id',
        'type',
        'filters',
        'field_ids',
        'pages_total',
        'documents_total',
        'max_documents',
        'estimated_cost_usd',
        'status',
        'requested_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'field_ids' => 'array',
        'pages_total' => 'integer',
        'pages_done' => 'integer',
        'documents_total' => 'integer',
        'max_documents' => 'integer',
        'documents_done' => 'integer',
        'current_document_id' => 'integer',
        'estimated_cost_usd' => 'decimal:2',
        'cost_so_far_usd' => 'decimal:4',
        'finished_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'pages_total' => 0,
        'pages_done' => 0,
        'documents_total' => 0,
        'documents_done' => 0,
        'estimated_cost_usd' => 0,
        'cost_so_far_usd' => 0,
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ArchiveAiProposal::class);
    }

    /** Batches the worker should pick up. */
    public function scopeRunnable(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_OVER_BUDGET], true);
    }

    public function percent(): int
    {
        $total = (int) $this->documents_total;

        return $total > 0 ? (int) round(min(1, $this->documents_done / $total) * 100) : 0;
    }

    /**
     * Add what one turn at a document cost.
     *
     * $documents is 0 when this is another turn at the document already being
     * worked, so `documents_done` counts DOCUMENTS. A read batch takes ten pages
     * at a time and comes back to the same document until it is read out, and
     * counting each turn made the progress bar and the batch's own limit both
     * wrong for anything longer than ten pages.
     */
    public function addProgress(int $pages, float $cost, int $documents = 1, ?int $documentId = null): void
    {
        $this->forceFill([
            'pages_done' => (int) $this->pages_done + $pages,
            'documents_done' => (int) $this->documents_done + $documents,
            'current_document_id' => $documentId ?? $this->current_document_id,
            'cost_so_far_usd' => (float) $this->cost_so_far_usd + $cost,
            'status' => self::STATUS_RUNNING,
        ])->save();
    }

    public function finish(string $status = self::STATUS_DONE, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'finished_at' => now(),
        ])->save();
    }
}
