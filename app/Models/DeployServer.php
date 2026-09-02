<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Linux host the team deploys to over SSH.
 *
 * Credentials are encrypted at rest and never rendered back into a form — the
 * edit page shows the key's filename and fingerprint with a "Replace key"
 * control, the same shape as Credential's hidden password.
 */
class DeployServer extends Model
{
    protected $fillable = [
        'name',
        'hostname',
        'port',
        'username',
        'auth_type',
        'private_key',
        'key_passphrase',
        'password',
        'key_filename',
        'key_fingerprint',
        'key_format',
        'working_directory',
        'description',
        'branch_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
        'key_passphrase' => 'encrypted',
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'port' => 'integer',
    ];

    /** Never in toArray/toJson — these leak into logs and API responses otherwise. */
    protected $hidden = ['private_key', 'key_passphrase', 'password'];

    // ─── Relationships ────────────────────────────────────────────

    public function commands(): HasMany
    {
        return $this->hasMany(DeployCommand::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeCommands(): HasMany
    {
        return $this->commands()->where('is_active', true);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DeployRun::class)->orderByDesc('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function usesKey(): bool
    {
        return $this->auth_type === 'key';
    }

    /** True once there is something to authenticate with. */
    public function hasCredentials(): bool
    {
        return $this->usesKey()
            ? filled($this->getRawOriginal('private_key'))
            : filled($this->getRawOriginal('password'));
    }

    public function target(): string
    {
        return "{$this->username}@{$this->hostname}:{$this->port}";
    }

    public function lastRun(): ?DeployRun
    {
        return $this->runs()->first();
    }

    /**
     * Safe payload for ActivityLog — every secret stripped.
     * Mirrors VoiceMeshNode::redactedForLog().
     */
    public function redactedForLog(): array
    {
        return collect($this->getAttributes())
            ->except(['private_key', 'key_passphrase', 'password'])
            ->all();
    }
}
