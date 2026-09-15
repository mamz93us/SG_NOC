<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One archive: an ArcMate project mirrored here, or one created in this portal.
 *
 * `mode` is the whole lifecycle:
 *   mirror     — ArcMate still owns it; this side is read-only and fed by the
 *                sync. Every archive starts here.
 *   native     — this portal owns it; documents are filed here. An archive
 *                reaches this only at cutover, after a final sync.
 *   read_only  — history, closed for good. Most of the twelve end up here.
 *
 * `readable` is separate from `mode` and is about the FILES, not the workflow:
 * the ArcMate "Test" project was stored with <Encrypt>1</Encrypt>, so its bytes
 * are ciphertext only ArcMate can undo. Nothing can read them, so the sync, the
 * transfer and AI all skip such an archive and the portal says why instead of
 * showing broken documents.
 */
class Archive extends Model
{
    public const MODE_MIRROR = 'mirror';

    public const MODE_NATIVE = 'native';

    public const MODE_READ_ONLY = 'read_only';

    public const MODES = [
        self::MODE_MIRROR => 'Mirrored from ArcMate (read-only here)',
        self::MODE_NATIVE => 'In use here (new documents filed in this portal)',
        self::MODE_READ_ONLY => 'History (closed, read-only)',
    ];

    protected $fillable = [
        'archive_source_id',
        'slug',
        'name',
        'name_ar',
        'description',
        'arcmate_folder',
        'arcmate_database',
        'mode',
        'readable',
        'unreadable_reason',
        'ai_chat',
        'ai_reading',
        'ai_extract',
        'sort_order',
        'transfer_paused',
        'transfer_priority',
    ];

    protected $casts = [
        'transfer_paused' => 'boolean',
        'transfer_priority' => 'integer',
        'readable' => 'boolean',
        'ai_chat' => 'boolean',
        'ai_reading' => 'boolean',
        'ai_extract' => 'boolean',
        'last_doc_arc_id' => 'integer',
        'last_file_arc_id' => 'integer',
        'last_doc_track_arc_id' => 'integer',
        'last_file_track_arc_id' => 'integer',
        'last_deleted_arc_id' => 'integer',
        'backfill_done_at' => 'datetime',
        'document_count' => 'integer',
        'file_count' => 'integer',
        'byte_total' => 'integer',
        'counts_updated_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    /**
     * Defaults for a new row in PHP, not only in the database — see the note on
     * ArchiveSource::$attributes for the bug this prevents.
     *
     * `readable` is the dangerous one to leave null: the transfer and the AI
     * both skip an archive that is not readable, so a null would quietly
     * exclude a perfectly good archive from both.
     */
    protected $attributes = [
        'mode' => self::MODE_MIRROR,
        'readable' => true,
        'ai_chat' => false,
        'ai_reading' => false,
        'ai_extract' => false,
        'transfer_paused' => false,
        'transfer_priority' => 100,
        'sort_order' => 0,
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ArchiveSource::class, 'archive_source_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ArchiveField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ArchiveMember::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArchiveDocument::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArchiveFile::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /** Archives whose files can actually be opened (see the class note). */
    public function scopeReadable(Builder $query): Builder
    {
        return $query->where('readable', true);
    }

    public function scopeMirrored(Builder $query): Builder
    {
        return $query->where('mode', self::MODE_MIRROR);
    }

    /** Archives the sync should still read from ArcMate. */
    public function scopeSyncable(Builder $query): Builder
    {
        return $query->readable()
            ->whereNotNull('arcmate_database')
            ->whereIn('mode', [self::MODE_MIRROR, self::MODE_READ_ONLY]);
    }

    // ─── State ───────────────────────────────────────────────────

    public function isMirrored(): bool
    {
        return $this->mode === self::MODE_MIRROR;
    }

    /** Whether new documents may be filed into this archive from the portal. */
    public function acceptsNewDocuments(): bool
    {
        return $this->mode === self::MODE_NATIVE && $this->readable;
    }

    /**
     * Whether a person may change index values here.
     *
     * Never while ArcMate still owns the archive: ArcMate would not learn about
     * the edit, and the next sync would be comparing against a value that only
     * exists on this side.
     */
    public function allowsValueEdits(): bool
    {
        return $this->mode === self::MODE_NATIVE;
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : ($this->slug ?: 'Archive');
    }

    /**
     * Recount the figures shown on the archive cards.
     *
     * Stored rather than counted per page load: counting 513,381 documents on
     * every visit is a self-inflicted slow page.
     *
     * One implementation, called by the sync, the recount task and filing alike.
     * There were three, and they disagreed — one omitted `byte_total`, another
     * counted through a relation and so missed the soft-delete guard, which is
     * how a card and a recount end up reporting different totals for the same
     * archive with nothing obviously wrong.
     */
    public function refreshCounts(): void
    {
        $this->forceFill([
            'document_count' => \Illuminate\Support\Facades\DB::table('archive_documents')
                ->where('archive_id', $this->getKey())
                ->where('status', self::documentActiveStatus())
                ->whereNull('deleted_at')
                ->count(),
            'file_count' => \Illuminate\Support\Facades\DB::table('archive_files')
                ->where('archive_id', $this->getKey())
                ->count(),
            'byte_total' => (int) \Illuminate\Support\Facades\DB::table('archive_files')
                ->where('archive_id', $this->getKey())
                ->sum('size'),
            'counts_updated_at' => now(),
        ])->save();
    }

    /** Indirect so this model does not import ArchiveDocument just for a string. */
    private static function documentActiveStatus(): string
    {
        return ArchiveDocument::STATUS_ACTIVE;
    }

    /** The field whose `arcmate_column` is this ArcMate column (S1, D1, C1 …). */
    public function fieldForArcMateColumn(string $column): ?ArchiveField
    {
        return $this->fields->first(
            fn (ArchiveField $field) => strcasecmp((string) $field->arcmate_column, $column) === 0
        );
    }
}
