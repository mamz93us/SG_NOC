<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A PDF being read, translated and turned into a knowledge article by
 * `ai:import-pdfs` — see PdfKnowledgeImporter for the work, and the migration
 * for why `pages` exists.
 *
 * The row outlives the work on purpose: it holds the original PDF, so whoever
 * reviews the article can check the translation against the document itself.
 * Deleting the article deletes its import, and the PDF with it.
 */
class AiKnowledgeImport extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'file_path', 'file_name', 'file_size', 'file_hash',
        'status', 'page_count', 'pages_done', 'pages', 'source_language', 'attempts', 'error',
        'prompt_tokens', 'completion_tokens',
        'category', 'audience', 'audience_branch_id', 'audience_department_id', 'publish',
        'article_id', 'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'page_count' => 'integer',
        'pages_done' => 'integer',
        'pages' => 'array',
        'attempts' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
        'publish' => 'boolean',
        'article_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $import) {
            try {
                Storage::disk('private')->delete($import->file_path);
            } catch (\Throwable) {
                // An orphaned file is not worth failing the delete over.
            }
        });
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still has work waiting for ai:import-pdfs. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::QUEUED, self::PROCESSING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => 'Queued',
            self::PROCESSING => $this->page_count
                ? 'Reading page '.min($this->pages_done + 1, $this->page_count).' of '.$this->page_count
                : 'Starting',
            self::DONE => 'Done',
            self::FAILED => 'Failed',
            default => ucfirst((string) $this->status),
        };
    }

    public function progressPercent(): int
    {
        return $this->page_count ? (int) floor(100 * $this->pages_done / $this->page_count) : 0;
    }

    /** "Arabic", "English" … — the language most of the document's pages are in. */
    public function sourceLanguageName(): ?string
    {
        if (! $this->source_language) {
            return null;
        }

        return class_exists(\Locale::class)
            ? \Locale::getDisplayLanguage($this->source_language, 'en')
            : strtoupper($this->source_language);
    }

    /**
     * Arabic stored as Arabic rather than ا escapes: `pages` is a whole
     * document, and escaped it is three times the size and unreadable in the
     * database.
     */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
