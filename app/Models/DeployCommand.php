<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One button on a deployment server's page. The body is free-form: an inline
 * snippet or a call to a script on the target host.
 */
class DeployCommand extends Model
{
    protected $fillable = [
        'deploy_server_id',
        'name',
        'kind',
        'command',
        'working_directory',
        'timeout_seconds',
        'confirm_required',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'confirm_required' => 'boolean',
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
        'sort_order' => 'integer',
    ];

    public const KINDS = ['deploy', 'logs', 'restart', 'status', 'custom'];

    // ─── Relationships ────────────────────────────────────────────

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeployServer::class, 'deploy_server_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DeployRun::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * The command as it will actually be sent, with the effective working
     * directory prepended. Falls back to the server's default directory.
     */
    public function resolvedCommand(): string
    {
        $dir = $this->working_directory ?: $this->server?->working_directory;
        $cmd = trim($this->command);

        return $dir ? 'cd '.escapeshellarg($dir).' && '.$cmd : $cmd;
    }

    public function buttonClass(): string
    {
        return match ($this->kind) {
            'deploy' => 'btn-success',
            'logs' => 'btn-outline-info',
            'restart' => 'btn-outline-warning',
            'status' => 'btn-outline-secondary',
            default => 'btn-outline-primary',
        };
    }

    public function icon(): string
    {
        return match ($this->kind) {
            'deploy' => 'bi-rocket-takeoff-fill',
            'logs' => 'bi-file-earmark-text',
            'restart' => 'bi-arrow-clockwise',
            'status' => 'bi-activity',
            default => 'bi-terminal',
        };
    }
}
