<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An ad-hoc AvePoint backup (mailbox or OneDrive) requested from the
 * AvePoint admin module — independent of the offboarding workflow.
 *
 * Lives in Azure Blob under the azure_avepoint disk; download is proxied
 * through NOC at /avepoint/download/{download_token}.
 */
class AvepointBackup extends Model
{
    protected $fillable = [
        'subject_upn',
        'subject_name',
        'subject_identity_user_id',
        'subject_employee_id',
        'requested_by_user_id',
        'notes',
        'type',
        'source',
        'avepoint_job_id',
        'status',
        'file_path',
        'file_size',
        'file_sha256',
        'download_token',
        'download_expires_at',
        'requester_notified_at',
        'error_message',
    ];

    protected $casts = [
        'file_size'             => 'integer',
        'download_expires_at'   => 'datetime',
        'requester_notified_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function subjectIdentityUser(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'subject_identity_user_id');
    }

    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function downloadAudits(): HasMany
    {
        return $this->hasMany(AvepointDownloadAudit::class);
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
            default    => $this->type,
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'pending', 'running'             => 'bg-info text-dark',
            'uploading'                      => 'bg-primary',
            'completed'                      => 'bg-success',
            'manual_upload_required'         => 'bg-warning text-dark',
            'failed'                         => 'bg-danger',
            'pruned'                         => 'bg-secondary',
            default                          => 'bg-secondary',
        };
    }
}
