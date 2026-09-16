<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One archived document: its index values and its files.
 *
 * `captured_at` is wall clock exactly as ArcMate holds it — Saudi local time,
 * stored and read without any timezone conversion, the same rule as attendance
 * punch times. ArcMate's tblDocuments has no date column at all, so the value
 * comes from the timestamp its stored file names begin with (ArcMate names every
 * file `yyyyMMddHHmmss…`), falling back to the earliest tblDocumentsTrack row.
 *
 * `needs_review` is how a conflict is surfaced rather than resolved: if ArcMate
 * later holds a different value for a field a person (or an approved AI
 * proposal) set here, the sync refuses to overwrite it and flags the document.
 */
class ArchiveDocument extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    /** Deleted in ArcMate. Kept here, hidden, so a mistake stays recoverable. */
    public const STATUS_DELETED_IN_ARCMATE = 'deleted_in_arcmate';

    protected $fillable = [
        'archive_id',
        'arcmate_id',
        'status',
        'needs_review',
        'review_note',
        'captured_at',
        'created_by_user_id',
        'created_by_name',
        'file_count',
        'page_count',
    ];

    protected $casts = [
        'arcmate_id' => 'integer',
        'needs_review' => 'boolean',
        'captured_at' => 'datetime',
        'file_count' => 'integer',
        'page_count' => 'integer',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ArchiveDocumentValue::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArchiveFile::class)->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_ACTIVE);
    }

    /**
     * Index values keyed by field key, for display and for the search form.
     *
     * @return array<string,string>
     */
    public function valueMap(): array
    {
        $map = [];

        foreach ($this->values as $value) {
            $key = $value->field?->key;
            if ($key !== null) {
                $map[$key] = (string) $value->value_text;
            }
        }

        return $map;
    }

    /**
     * A one-line label for lists: the first filled value, which for invoices is
     * the invoice number. Falls back to the capture date, then the id — a
     * document with every field empty still has to be findable.
     */
    public function title(): string
    {
        foreach ($this->values as $value) {
            $text = trim((string) $value->value_text);
            if ($text !== '') {
                return $text;
            }
        }

        return $this->captured_at?->format('Y-m-d H:i') ?? ('#'.$this->getKey());
    }
}
