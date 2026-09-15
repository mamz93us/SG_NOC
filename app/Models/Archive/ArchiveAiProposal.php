<?php

namespace App\Models\Archive;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What AI thinks a field should say — and nothing more than that.
 *
 * A proposal is never a value. Somebody approves it, and only then is it
 * written to the document with source `ai_approved` and their name on it. That
 * is the whole reason this table exists rather than the extractor writing
 * straight into archive_document_values: these are invoices and contracts, and
 * a confident machine is still a machine.
 *
 * `evidence_page` is what makes review possible instead of theatre — a reviewer
 * can open that page and look, rather than taking the number on trust.
 *
 * Excluded from the automatic audit: a batch creates thousands of these. The
 * approval, which writes a real value, IS audited.
 */
class ArchiveAiProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EDITED = 'edited';

    public const STATUS_REJECTED = 'rejected';

    /**
     * AI read the document and the field is simply not on it.
     *
     * Deliberately not `rejected` — nobody rejected anything, and it never
     * reaches the review queue (see scopePending). It is recorded for two
     * reasons: a reviewer chasing a missing invoice number can see that it was
     * looked for, and BatchRunner picks documents that have no proposal row for
     * a field, so without this a document AI finds nothing on would be chosen —
     * and paid for — again on every slice for as long as the batch lives.
     */
    public const STATUS_NOT_FOUND = 'not_found';

    protected $fillable = [
        'archive_ai_batch_id',
        'archive_document_id',
        'archive_field_id',
        'value',
        'confidence',
        'evidence_page',
        'status',
        'approved_value',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'evidence_page' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ArchiveAiBatch::class, 'archive_ai_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ArchiveDocument::class, 'archive_document_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ArchiveField::class, 'archive_field_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** The value that would be written: what a reviewer typed, else AI's. */
    public function finalValue(): ?string
    {
        return $this->status === self::STATUS_EDITED
            ? $this->approved_value
            : $this->value;
    }

    public function isDecided(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }
}
