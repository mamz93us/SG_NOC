<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $device_mac_id
 * @property bool        $radius_enabled
 * @property int|null    $vlan_override
 * @property string|null $notes
 * @property int|null    $created_by
 */
class RadiusMacOverride extends Model
{
    protected $fillable = [
        'device_mac_id',
        'radius_enabled',
        'vlan_override',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'radius_enabled' => 'boolean',
        'vlan_override'  => 'integer',
    ];

    public function deviceMac(): BelongsTo
    {
        return $this->belongsTo(DeviceMac::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
