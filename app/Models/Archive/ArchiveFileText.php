<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The readable text of one page of one file.
 *
 * Almost every scan in the archive is a picture of a page with no text in it —
 * of 93 files probed across all twelve archives, only two contained real text —
 * so this table is what makes word search and answering questions possible at
 * all. Rows arrive from three places, cheapest first:
 *
 *   arcmate_ocr — ArcMate's own OCR (tblFiles.arcFullText), free, already paid for
 *   pdf_text    — the PDF's embedded text layer via pdftotext, free
 *   ai          — gpt-4o reading the page image, which costs money per page
 *
 * One row per page, and a page is only ever read once: `read_at` plus the unique
 * (file, page) key are what stop a second question about the same document
 * paying to read it again.
 */
class ArchiveFileText extends Model
{
    public const SOURCE_ARCMATE_OCR = 'arcmate_ocr';

    public const SOURCE_PDF_TEXT = 'pdf_text';

    public const SOURCE_AI = 'ai';

    /** No updated_at: a page's text is written once and not revised. */
    public $timestamps = false;

    protected $fillable = [
        'archive_file_id',
        'page',
        'text',
        'source',
        'read_at',
    ];

    protected $casts = [
        'page' => 'integer',
        'read_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'archive_file_id');
    }

    /** Whether this page cost an AI call, for the usage figures. */
    public function isFromAi(): bool
    {
        return $this->source === self::SOURCE_AI;
    }
}
