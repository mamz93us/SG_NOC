<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserSessionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'browser_session_id',
        'session_id',
        'event_type',
        'message',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class);
    }

    public static function eventTypeLabels(): array
    {
        return [
            'launch_requested'  => 'Launch requested',
            'launch_succeeded'  => 'Launch succeeded',
            'launch_failed'     => 'Launch failed',
            'stopped'           => 'Stopped by user',
            'force_stopped'     => 'Force-stopped by admin',
            'idle_stopped'      => 'Idle timeout',
            'heartbeat'         => 'Heartbeat',
            'container_crashed' => 'Container crashed',
            'permission_denied' => 'Permission denied',
            'shared'            => 'Shared with another user',
            'settings_changed'  => 'Portal settings changed',
        ];
    }

    public function eventTypeLabel(): string
    {
        return self::eventTypeLabels()[$this->event_type] ?? $this->event_type;
    }
}
