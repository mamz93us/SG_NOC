<?php

namespace App\Models\Archive;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something scanned that has arrived but is not filed yet.
 *
 * The waiting room between capture and the archive. It exists because filing is
 * a judgement — which archive, which invoice number — and a scan arriving from
 * an MFP at 3am has nobody to make it. So the bytes are safe immediately, AI
 * reads the pages and suggests the values, and a person finishes the job.
 *
 * Excluded from the automatic audit (see config/audit.php): the worker rewrites
 * ai_status and ai_suggestions as it goes. The event that matters is the filing,
 * which is audited where it happens — the document and its values being written.
 */
class ArchiveInboxItem extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_FOLDER = 'folder';

    public const SOURCE_EMAIL = 'email';

    public const SOURCE_AGENT = 'agent';

    public const SOURCES = [
        self::SOURCE_UPLOAD => 'Uploaded',
        self::SOURCE_FOLDER => 'Scan folder',
        self::SOURCE_EMAIL => 'Scan e-mail',
        self::SOURCE_AGENT => 'Scanned here',
    ];

    public const STATUS_WAITING = 'waiting';

    public const STATUS_FILED = 'filed';

    public const STATUS_DISCARDED = 'discarded';

    /** Nothing has looked at it yet. */
    public const AI_NONE = 'none';

    public const AI_QUEUED = 'queued';

    public const AI_READING = 'reading';

    public const AI_DONE = 'done';

    public const AI_FAILED = 'failed';

    /** Give up on reading an item after this many tries and let a person file it. */
    public const MAX_AI_ATTEMPTS = 3;

    protected $fillable = [
        'user_id',
        'archive_id',
        'source',
        'source_detail',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'pages',
        'sha256',
        'status',
        'ai_status',
        'ai_suggestions',
        'ai_text',
        'ai_archive_id',
        'ai_confidence',
        'meta',
        'received_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'pages' => 'integer',
        'ai_suggestions' => 'array',
        'ai_text' => 'array',
        'ai_confidence' => 'integer',
        'ai_attempts' => 'integer',
        'meta' => 'array',
        'received_at' => 'datetime',
        'filed_at' => 'datetime',
    ];

    /**
     * Defaults in PHP as well as in the database — see the note on
     * ArchiveSource::$attributes for the bug this prevents. `status` and
     * `ai_status` are the dangerous ones to leave null here: every scope below
     * compares them, and a null would put a freshly created item in no list at
     * all, which reads as an upload that silently vanished.
     */
    protected $attributes = [
        'source' => self::SOURCE_UPLOAD,
        'disk' => ArchiveFile::DISK_AZURE,
        'status' => self::STATUS_WAITING,
        'ai_status' => self::AI_NONE,
        'ai_attempts' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The archive this arrived FOR, where a scan address or folder named one. */
    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    /** The archive AI thinks it belongs in — a suggestion, never a decision. */
    public function suggestedArchive(): BelongsTo
    {
        return $this->belongsTo(Archive::class, 'ai_archive_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ArchiveDocument::class, 'archive_document_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_WAITING);
    }

    /**
     * The worker's queue: waiting, not yet read, and not already given up on.
     *
     * `reading` is included deliberately. An item is marked reading before the
     * first Azure call, so a worker killed mid-read would otherwise leave it
     * stuck there for good; the attempt counter is what stops it looping.
     */
    public function scopeNeedingAi(Builder $query): Builder
    {
        return $query->waiting()
            ->whereIn($query->qualifyColumn('ai_status'), [self::AI_NONE, self::AI_QUEUED, self::AI_READING])
            ->where($query->qualifyColumn('ai_attempts'), '<', self::MAX_AI_ATTEMPTS);
    }

    // ─── State ───────────────────────────────────────────────────

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /** A name worth showing: an MFP's own filenames are timestamps. */
    public function displayName(): string
    {
        $original = trim((string) $this->original_name);

        return $original !== '' ? $original : basename((string) $this->path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo((string) ($this->original_name ?: $this->path), PATHINFO_EXTENSION));
    }

    public function isPdf(): bool
    {
        return $this->extension() === 'pdf';
    }

    public function isTiff(): bool
    {
        return in_array($this->extension(), ['tif', 'tiff'], true);
    }

    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'bmp', 'webp'], true);
    }

    /** Whether there are pages in it to read at all. */
    public function isReadable(): bool
    {
        return $this->isPdf() || $this->isTiff() || $this->isImage();
    }

    /**
     * What AI suggested for one field key, or null.
     *
     * @return array{value:string, confidence:int, page:?int}|null
     */
    public function suggestionFor(string $key): ?array
    {
        $suggestion = ($this->ai_suggestions ?? [])[$key] ?? null;

        return is_array($suggestion) && isset($suggestion['value']) ? $suggestion : null;
    }

    public function markAiFailed(string $error): void
    {
        $this->forceFill([
            'ai_status' => (int) $this->ai_attempts + 1 >= self::MAX_AI_ATTEMPTS
                ? self::AI_FAILED
                : self::AI_QUEUED,
            'ai_attempts' => (int) $this->ai_attempts + 1,
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
