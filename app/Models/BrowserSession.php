<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrowserSession extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'container_name',
        'volume_name',
        'internal_ip',
        'webrtc_port_start',
        'webrtc_port_end',
        'status',
        'neko_user_password_hash',
        'last_active_at',
        'stopped_at',
        'error_message',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'stopped_at'     => 'datetime',
    ];

    protected $hidden = [
        'neko_user_password_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrowserSessionEvent::class)->orderByDesc('created_at');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['starting', 'running']);
    }

    public function scopeIdleSince($query, \DateTimeInterface $cutoff)
    {
        return $query->where('status', 'running')
                     ->where(function ($q) use ($cutoff) {
                         $q->whereNull('last_active_at')
                           ->orWhere('last_active_at', '<', $cutoff);
                     });
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['starting', 'running'], true);
    }
}
