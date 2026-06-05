<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A per-device backup login (the "user & password manager"). Each row maps 1:1
 * to an SFTPGo virtual user the NOC provisions/rotates over REST. The password
 * is encrypted at rest and revealable like a Credential. Monitoring fields are
 * stamped by the upload webhook (last_received_at) and the sweeper
 * (last_archived_at); backups:check-overdue maintains last_status.
 */
class BackupAccount extends Model
{
    public const FREQ_DAILY = 'daily';

    public const FREQ_WEEKLY = 'weekly';

    public const FREQ_MONTHLY = 'monthly';

    public const FREQ_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'device_type',
        'device_id',
        'label',
        'sftpgo_username',
        'password',
        'protocols',
        'home_dir',
        'quota_mb',
        'expected_frequency',
        'grace_minutes',
        'last_received_at',
        'last_archived_at',
        'last_status',
        'is_active',
        'created_by',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'protocols' => 'array',
        'quota_mb' => 'integer',
        'grace_minutes' => 'integer',
        'is_active' => 'boolean',
        'last_received_at' => 'datetime',
        'last_archived_at' => 'datetime',
    ];

    // ─── Encrypted password (revealable, like Credential) ─────────

    public function getPasswordAttribute($value): string
    {
        if (empty($value)) {
            return '';
        }
        try {
            return decrypt($value);
        } catch (\Exception) {
            return '********';
        }
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? encrypt($value) : null;
    }

    // ─── Relationships ────────────────────────────────────────────

    public function device(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(SftpBackup::class, 'account_id')->latest();
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Active accounts the overdue monitor has flagged (last_status maintained by backups:check-overdue). */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('last_status', self::STATUS_OVERDUE);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function deviceLabel(): string
    {
        return $this->device?->name ?? $this->label ?? $this->sftpgo_username;
    }

    /** SFTPGo protocol names this account may use (defaults to SFTP-only). */
    public function allowedProtocols(): array
    {
        $protocols = array_values(array_filter((array) $this->protocols));

        return $protocols ?: ['SFTP'];
    }

    /** Quota in bytes for SFTPGo (0 = unlimited). */
    public function quotaBytes(): int
    {
        return (int) ($this->quota_mb ?? 0) * 1024 * 1024;
    }

    public function homeDir(): string
    {
        if ($this->home_dir) {
            return $this->home_dir;
        }
        $root = rtrim(Setting::get()->sftpgo_home_root ?: '/srv/backups', '/');

        return $root.'/'.$this->sftpgo_username;
    }

    public function expectedWindowMinutes(): ?int
    {
        return match ($this->expected_frequency) {
            self::FREQ_DAILY => 1440,
            self::FREQ_WEEKLY => 10080,
            self::FREQ_MONTHLY => 43200,
            default => null, // manual — never auto-overdue
        };
    }

    public function lastBackupAt(): ?Carbon
    {
        return $this->last_archived_at ?? $this->last_received_at;
    }

    /** Live overdue check (the scheduled monitor persists this into last_status). */
    public function isOverdue(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $window = $this->expectedWindowMinutes();
        if ($window === null) {
            return false;
        }
        $baseline = $this->lastBackupAt() ?? $this->created_at;

        return $baseline !== null && $baseline->lt(now()->subMinutes($window + (int) $this->grace_minutes));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->last_status) {
            self::STATUS_ARCHIVED => 'bg-success',
            self::STATUS_RECEIVED => 'bg-info text-dark',
            self::STATUS_OVERDUE => 'bg-danger',
            self::STATUS_FAILED => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
    }
}
