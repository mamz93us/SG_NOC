<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UcmTrunkCache extends Model
{
    protected $table = 'ucm_trunks_cache';

    protected $fillable = [
        'ucm_id', 'trunk_name', 'trunk_index',
        'host', 'status', 'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
    ];

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_id');
    }

    public function isReachable(): bool
    {
        return !str_contains(strtolower($this->status), 'unreachable');
    }

    public function statusBadgeClass(): string
    {
        return $this->isReachable() ? 'bg-success' : 'bg-danger';
    }
}
