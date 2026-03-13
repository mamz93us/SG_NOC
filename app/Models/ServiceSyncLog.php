<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSyncLog extends Model
{
    protected $fillable = [
        'service',
        'status',
        'records_synced',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the most recent log entry for a given service.
     */
    public static function lastFor(string $service): ?static
    {
        return static::where('service', $service)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Get the most recent COMPLETED entry for a service.
     */
    public static function lastSuccessFor(string $service): ?static
    {
        return static::where('service', $service)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * Record the start of a sync run (returns the log entry).
     */
    public static function start(string $service): static
    {
        // Mark any stale 'running' entries older than 2 hours as failed
        // (identity sync can take 30-60 min for large tenants)
        static::where('service', $service)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status'        => 'failed',
                'error_message' => 'Sync process timed out (exceeded 2-hour window).',
                'completed_at'  => now(),
            ]);

        return static::create([
            'service'    => $service,
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Duration in seconds between started_at and completed_at.
     */
    public function durationSeconds(): ?int
    {
        if (!$this->started_at || !$this->completed_at) return null;
        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    public function statusBadgeClass(): string
    {
        return match($this->status) {
            'completed' => 'bg-success',
            'running'   => 'bg-warning text-dark',
            'failed'    => 'bg-danger',
            default     => 'bg-secondary',
        };
    }
}
