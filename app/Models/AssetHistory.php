<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'event_type', 'user_id', 'description', 'meta'];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    const EVENT_TYPES = [
        'created', 'assigned', 'returned', 'maintenance', 'repair',
        'retired', 'disposed', 'license_assigned', 'license_removed', 'note_added',
    ];

    const EVENT_ICONS = [
        'created'          => 'bi-plus-circle text-success',
        'assigned'         => 'bi-person-check text-primary',
        'returned'         => 'bi-arrow-return-left text-secondary',
        'maintenance'      => 'bi-wrench text-warning',
        'repair'           => 'bi-tools text-danger',
        'retired'          => 'bi-archive text-muted',
        'disposed'         => 'bi-trash text-danger',
        'license_assigned' => 'bi-key text-info',
        'license_removed'  => 'bi-key-fill text-secondary',
        'note_added'       => 'bi-sticky text-secondary',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a history event for a device.
     */
    public static function record(Device $device, string $event, string $description, array $meta = []): self
    {
        return static::create([
            'device_id'   => $device->id,
            'event_type'  => $event,
            'user_id'     => auth()->id(),
            'description' => $description,
            'meta'        => $meta ?: null,
            'created_at'  => now(),
        ]);
    }

    public function eventIcon(): string
    {
        return self::EVENT_ICONS[$this->event_type] ?? 'bi-circle text-secondary';
    }

    public function eventLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->event_type));
    }
}
