<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field's value on one document.
 *
 * `value_text` is always filled — it is what people search on and what the
 * document lists show. `value_date` and `value_number` are filled as well when
 * the field carries that type, so a range search ("March 2024", "over 10,000")
 * is an indexed comparison instead of string parsing. ArcMate itself stores
 * everything as varchar, amounts included, which is exactly why the typed
 * columns are worth keeping on this side.
 *
 * `source` is what protects human work from the sync:
 *   arcmate      — copied from ArcMate, so the sync may refresh it freely
 *   person       — typed here
 *   ai_approved  — proposed by AI and approved by a named reviewer
 * The sync only ever overwrites `arcmate`; the other two make it flag the
 * document for review instead.
 */
class ArchiveDocumentValue extends Model
{
    public const SOURCE_ARCMATE = 'arcmate';

    public const SOURCE_PERSON = 'person';

    public const SOURCE_AI_APPROVED = 'ai_approved';

    protected $fillable = [
        'archive_document_id',
        'archive_field_id',
        'value_text',
        'value_date',
        'value_number',
        'source',
        'set_by_user_id',
    ];

    protected $casts = [
        'value_date' => 'date',
        'value_number' => 'decimal:4',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ArchiveDocument::class, 'archive_document_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ArchiveField::class, 'archive_field_id');
    }

    /** Whether the sync may replace this value without asking anyone. */
    public function isFromArcMate(): bool
    {
        return $this->source === self::SOURCE_ARCMATE;
    }
}
