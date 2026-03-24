<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryScan extends Model
{
    protected $fillable = [
        'name', 'range_input', 'branch_id', 'snmp_community', 'snmp_timeout',
        'status', 'total_hosts', 'reachable_count', 'error_message',
        'started_at', 'finished_at', 'created_by',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(DiscoveryResult::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'running'   => 'primary',
            'failed'    => 'danger',
            default     => 'secondary',
        };
    }

    public function duration(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }
        $secs = $this->started_at->diffInSeconds($this->finished_at);
        return $secs < 60 ? "{$secs}s" : round($secs / 60, 1) . 'm';
    }
}
