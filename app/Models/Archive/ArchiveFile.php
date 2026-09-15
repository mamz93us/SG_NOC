<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stored file of a document — a scanned PDF, a TIFF, an attached e-mail.
 *
 * `disk` is the single source of truth for where the bytes are, and it is what
 * makes the move to Azure invisible: a file is read from the read-only ArcMate
 * mount until the transfer worker has copied it and checked the copy, and from
 * `azure_archive` after that. Nothing else in the app has to know which stage
 * a file is at.
 *
 * The file-type helpers are deliberately based on the extension of the stored
 * name rather than the recorded mime: ArcMate never recorded a mime type, and
 * the probe of the real share found the extensions honest (PDF 1.2–1.4, G4
 * TIFFs, OLE .msg) in every archive but the encrypted "Test" project.
 */
class ArchiveFile extends Model
{
    public const DISK_ARCMATE = 'arcmate';

    public const DISK_AZURE = 'azure_archive';

    protected $fillable = [
        'archive_id',
        'archive_document_id',
        'arcmate_id',
        'position',
        'original_name',
        'disk',
        'path',
        'arcmate_path',
        'mime',
        'size',
        'page_count',
        'sha256',
        'arcmate_crc',
        'transferred_at',
        'transfer_attempts',
        'transfer_error',
    ];

    protected $casts = [
        'arcmate_id' => 'integer',
        'position' => 'integer',
        'size' => 'integer',
        'page_count' => 'integer',
        'transferred_at' => 'datetime',
        'transfer_attempts' => 'integer',
    ];

    /**
     * Defaults for a new row in PHP, not only in the database — see the note on
     * ArchiveSource::$attributes for the bug this prevents. `disk` matters most:
     * a file whose disk is null is a file nothing knows how to read.
     */
    protected $attributes = [
        'disk' => self::DISK_ARCMATE,
        'position' => 0,
        'transfer_attempts' => 0,
    ];

    /** Give up on a file after this many tries and let a person look at it. */
    public const MAX_TRANSFER_ATTEMPTS = 5;

    /**
     * The transfer worker's queue: still on the ArcMate share, still worth
     * trying, and in an archive that is readable and not paused.
     *
     * Newest first within an archive. If the move is ever interrupted for good,
     * the files people actually open are the ones that made it across.
     */
    public function scopeTransferQueue(Builder $query): Builder
    {
        return $query->where('archive_files.disk', self::DISK_ARCMATE)
            ->where('archive_files.transfer_attempts', '<', self::MAX_TRANSFER_ATTEMPTS)
            ->whereExists(function ($sub) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('archives')
                    ->whereColumn('archives.id', 'archive_files.archive_id')
                    ->where('archives.readable', true)
                    ->where('archives.transfer_paused', false);
            });
    }

    /** Files that have failed too often — the Transfer page's error list. */
    public function scopeTransferFailed(Builder $query): Builder
    {
        return $query->where('disk', self::DISK_ARCMATE)
            ->where('transfer_attempts', '>=', self::MAX_TRANSFER_ATTEMPTS);
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ArchiveDocument::class, 'archive_document_id');
    }

    public function texts(): HasMany
    {
        return $this->hasMany(ArchiveFileText::class)->orderBy('page');
    }

    /** Files still living on the ArcMate share — the transfer worker's queue. */
    public function scopeOnArcMate(Builder $query): Builder
    {
        return $query->where('disk', self::DISK_ARCMATE);
    }

    public function isOnArcMate(): bool
    {
        return $this->disk === self::DISK_ARCMATE;
    }

    // ─── Type ────────────────────────────────────────────────────

    public function extension(): string
    {
        return strtolower(pathinfo((string) $this->path, PATHINFO_EXTENSION));
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
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true);
    }

    public function isEmail(): bool
    {
        return $this->extension() === 'msg';
    }

    public function isHtml(): bool
    {
        return in_array($this->extension(), ['htm', 'html'], true);
    }

    /** Whether the portal can show this in the viewer rather than only offer it. */
    public function isViewable(): bool
    {
        return $this->isPdf() || $this->isImage() || $this->isTiff() || $this->isEmail() || $this->isHtml();
    }

    /**
     * The type to send with the bytes.
     *
     * Explicit rather than sniffed, because the app sends
     * X-Content-Type-Options: nosniff — a wrong or missing type means the
     * browser refuses to render the PDF instead of quietly guessing.
     */
    public function contentType(): string
    {
        return match ($this->extension()) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'htm', 'html' => 'text/html',
            'msg' => 'application/vnd.ms-outlook',
            'zip' => 'application/zip',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            default => $this->mime ?: 'application/octet-stream',
        };
    }

    /** A name worth offering on a download: ArcMate's timestamps are not one. */
    public function downloadName(): string
    {
        $original = trim((string) $this->original_name);

        if ($original !== '' && pathinfo($original, PATHINFO_EXTENSION) !== '') {
            return $original;
        }

        return basename((string) $this->path);
    }
}
