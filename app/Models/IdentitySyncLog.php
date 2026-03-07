<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentitySyncLog extends Model
{
    protected $fillable = [
        'type',
        'status',
        'users_synced',
        'licenses_synced',
        'groups_synced',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed' => 'bg-success',
            'failed'    => 'bg-danger',
            'started'   => 'bg-warning text-dark',
            default     => 'bg-secondary',
        };
    }

    public function durationSeconds(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }
        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
