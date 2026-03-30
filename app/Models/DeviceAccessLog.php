<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAccessLog extends Model
{
    public $timestamps  = false;
    public $updatedAt   = false;
    public $createdAt   = 'created_at';

    protected $fillable = [
        'device_id', 'user_id', 'access_type', 'action', 'client_ip', 'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Static helper ─────────────────────────────────────────────────────

    /**
     * Quick one-liner to record an access event.
     *
     * DeviceAccessLog::log($device, $user, 'ssh', 'session_start', $clientIp, ['key' => 'val']);
     */
    public static function log(
        Device  $device,
        ?User   $user,
        string  $accessType,
        string  $action,
        ?string $clientIp = null,
        array   $meta     = []
    ): self {
        return static::create([
            'device_id'   => $device->id,
            'user_id'     => $user?->id,
            'access_type' => $accessType,
            'action'      => $action,
            'client_ip'   => $clientIp,
            'meta'        => empty($meta) ? null : $meta,
        ]);
    }

    // ── Presentation ──────────────────────────────────────────────────────

    public function accessTypeBadgeClass(): string
    {
        return match ($this->access_type) {
            'ssh'    => 'bg-info',
            'web'    => 'bg-primary',
            'telnet' => 'bg-success',
            default  => 'bg-secondary',
        };
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'session_start' => 'SSH Started',
            'session_end'   => 'SSH Ended',
            'browse'        => 'Web Browse',
            'connect'       => 'Connected',
            'disconnect'    => 'Disconnected',
            default         => ucwords(str_replace('_', ' ', $this->action)),
        };
    }
}
