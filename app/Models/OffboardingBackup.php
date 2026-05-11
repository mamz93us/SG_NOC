<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One row per artifact produced by the offboarding flow:
 *   - mailbox: PST export of the user's Exchange Online mailbox
 *   - onedrive: zip of the user's OneDrive for Business
 *   - laptop: manually-uploaded archive of laptop data (when manager picks 'backup')
 *
 * File lives in Azure Blob; `file_path` is the blob key. Download is proxied
 * through NOC at /offboarding/download/{download_token}.
 */
class OffboardingBackup extends Model
{
    protected $fillable = [
        'offboarding_workflow_id',
        'type',
        'source',
        'avepoint_job_id',
        'status',
        'file_path',
        'file_size',
        'file_sha256',
        'download_token',
        'download_expires_at',
        'manager_notified_at',
        'error_message',
    ];

    protected $casts = [
        'file_size'            => 'integer',
        'download_expires_at'  => 'datetime',
        'manager_notified_at'  => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function offboardingWorkflow(): BelongsTo
    {
        return $this->belongsTo(OffboardingWorkflow::class);
    }

    public function downloadAudits(): HasMany
    {
        return $this->hasMany(OffboardingDownloadAudit::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function generateDownloadToken(int $expiryDays = 5): string
    {
        $this->download_token      = Str::random(64);
        $this->download_expires_at = now()->addDays($expiryDays);
        $this->save();

        return $this->download_token;
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'completed'
            && $this->file_path
            && $this->download_token
            && ($this->download_expires_at === null || $this->download_expires_at->isFuture());
    }

    public function humanSize(): string
    {
        if (! $this->file_size) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size  = (float) $this->file_size;
        $i     = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $size, $units[$i]);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'mailbox'  => 'Mailbox (PST)',
            'onedrive' => 'OneDrive (ZIP)',
            'laptop'   => 'Laptop Data',
            default    => $this->type,
        };
    }
}
