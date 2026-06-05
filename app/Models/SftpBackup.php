<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup file swept out of SFTPGo's backup root on the NOC and uploaded
 * to Azure Blob by the sftp-backups:sweep scheduled command.
 *
 * `azure_path` is derived deterministically from the source file (its inbox
 * path + mtime), so the same physical file always resolves to the same row.
 * That keeps the sweep idempotent: a crash between "uploaded to Azure" and
 * "deleted locally" just re-finds the row and the existing blob on the next
 * tick instead of uploading a duplicate. Rows survive blob pruning (status set
 * to `pruned`, azure_path cleared) so the audit trail is never lost.
 */
class SftpBackup extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_PRUNED = 'pruned';

    protected $fillable = [
        'account_id',
        'source',
        'relative_path',
        'filename',
        'size',
        'sha256',
        'disk',
        'azure_path',
        'status',
        'error',
        'received_at',
        'uploaded_at',
        'pruned_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'received_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'pruned_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(BackupAccount::class, 'account_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeUploaded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UPLOADED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /** Blobs still live in Azure (uploaded and not yet pruned). */
    public function scopeLiveInAzure(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UPLOADED)->whereNotNull('azure_path');
    }

    // ─── State helpers ────────────────────────────────────────────

    public function isUploaded(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function humanSize(): string
    {
        if (! $this->size) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
