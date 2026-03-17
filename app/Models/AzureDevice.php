<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AzureDevice extends Model
{
    protected $fillable = [
        'azure_device_id', 'display_name', 'device_type', 'os', 'os_version',
        'upn', 'serial_number', 'manufacturer', 'model', 'enrolled_date', 'last_sync_at',
        'last_activity_at', 'device_id', 'link_status', 'raw_data',
    ];

    protected $casts = [
        'enrolled_date'    => 'datetime',
        'last_sync_at'     => 'datetime',
        'last_activity_at' => 'datetime',
        'raw_data'         => 'array',
    ];

    const LINK_STATUSES = ['unlinked', 'linked', 'pending', 'rejected'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function linkStatusBadgeClass(): string
    {
        return match ($this->link_status) {
            'linked'   => 'success',
            'pending'  => 'warning',
            'rejected' => 'danger',
            default    => 'secondary',
        };
    }

    public function linkStatusLabel(): string
    {
        return ucfirst($this->link_status);
    }
}
