<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One terminal session (mode=shell) or command press (mode=exec).
 *
 * For exec runs the transcript and exit code are written by the Node proxy via
 * Internal\DeployRunReportController — never by the browser — so the record is
 * complete even if the user closes the tab mid-deploy.
 */
class DeployRun extends Model
{
    protected $fillable = [
        'deploy_server_id',
        'deploy_command_id',
        'user_id',
        'mode',
        'status',
        'exit_code',
        'output',
        'command_label',
        'timeout_seconds',
        'client_ip',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exit_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    // ─── Relationships ────────────────────────────────────────────

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeployServer::class, 'deploy_server_id');
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeployCommand::class, 'deploy_command_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function label(): string
    {
        return $this->command_label ?: ($this->mode === 'shell' ? 'Interactive shell' : 'Command');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'success' => 'bg-success',
            'failed' => 'bg-danger',
            'timeout' => 'bg-warning text-dark',
            'running' => 'bg-info text-dark',
            default => 'bg-secondary',
        };
    }

    public function durationLabel(): string
    {
        if ($this->duration_ms === null) {
            return $this->isRunning() ? 'running…' : '—';
        }

        $seconds = $this->duration_ms / 1000;

        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }

        return floor($seconds / 60).'m '.str_pad((string) (int) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }

    /**
     * Close a run that never reported back. Used by the reaper and by the
     * terminal page when an interactive session disconnects.
     */
    public function finish(string $status, ?int $exitCode = null, ?string $output = null): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $finishedAt = now();

        $this->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $output ?? $this->output,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->started_at
                ? max(0, (int) $this->started_at->diffInMilliseconds($finishedAt))
                : null,
        ])->save();
    }
}
