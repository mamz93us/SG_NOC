<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnLog extends Model
{
    protected $fillable = [
        'vpn_id',
        'event_type',
        'message',
    ];

    public function vpnTunnel(): BelongsTo
    {
        return $this->belongsTo(VpnTunnel::class, 'vpn_id');
    }
}
