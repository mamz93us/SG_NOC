<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per "download all CVs for this job" request.
 *
 * The heavy lifting (paging Teamtailor for every applicant, downloading each
 * résumé, zipping, uploading the zip to Azure Blob) happens asynchronously in
 * the teamtailor:process-cv-exports scheduled command — production runs no
 * queue worker, so a synchronous web request would time out on a job with
 * hundreds of applicants. The finished zip lives in Azure Blob; `file_path` is
 * the blob key and downloads are proxied through an admin-gated controller.
 */
class TeamtailorCvExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job_id',
        'job_title',
        'status',
        'total_candidates',
        'cv_count',
        'failed_count',
        'disk',
        'file_path',
        'file_size',
        'error',
        'requested_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_candidates' => 'integer',
        'cv_count' => 'integer',
        'failed_count' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    /** Oldest-first queue of work for the scheduled command to drain. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->orderBy('id');
    }

    // ─── State helpers ────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** Still queued or mid-flight — the UI shows a "preparing…" state. */
    public function inProgress(): bool
    {
        return $this->isPending() || $this->isProcessing();
    }

    public function isDownloadable(): bool
    {
        return $this->isCompleted()
            && $this->disk
            && $this->file_path;
    }

    public function humanSize(): string
    {
        if (! $this->file_size) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->file_size;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
