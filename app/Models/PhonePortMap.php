<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhonePortMap extends Model
{
    protected $table = 'phone_port_map';

    protected $fillable = [
        'ucm_server_id', 'extension', 'phone_ip', 'phone_mac',
        'switch_name', 'switch_serial', 'switch_port',
        'vlan', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'vlan'         => 'integer',
    ];

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_server_id');
    }

    public function locationLabel(): string
    {
        if ($this->switch_name && $this->switch_port) {
            return "{$this->switch_name} / Port {$this->switch_port}";
        }
        return '-';
    }
}
