<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mysqldump → Azure Blob run of the application database, created either
 * by the daily `db-backups:run` scheduled command or by the "Backup Now"
 * button on Admin → Server Status (which queues RunDatabaseBackupJob for the
 * every-minute drainer). Rows survive blob pruning (status set to `pruned`,
 * azure_path cleared) so the backup history is never lost.
 */
class DatabaseBackup extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PRUNED = 'pruned';

    public const VIA_SCHEDULED = 'scheduled';

    public const VIA_MANUAL = 'manual';

    protected $fillable = [
        'database',
        'filename',
        'size',
        'sha256',
        'disk',
        'azure_path',
        'status',
        'error',
        'triggered_via',
        'initiated_by',
        'started_at',
        'completed_at',
        'pruned_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'pruned_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeUploaded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UPLOADED);
    }

    /** Runs the worker still has to pick up or finish. */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
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

    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
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

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) abs($this->completed_at->diffInSeconds($this->started_at));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADED => 'bg-success',
            self::STATUS_RUNNING => 'bg-warning text-dark',
            self::STATUS_PENDING => 'bg-info text-dark',
            self::STATUS_FAILED => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
