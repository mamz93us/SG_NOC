<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UcmExtensionCache extends Model
{
    protected $table = 'ucm_extensions_cache';

    protected $fillable = [
        'ucm_id', 'extension', 'name', 'email',
        'ip_address', 'status', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_id');
    }

    public function phonePortMap()
    {
        return $this->hasOne(PhonePortMap::class, 'extension', 'extension')
            ->where('phone_port_map.ucm_server_id', $this->ucm_id);
    }

    public function statusBadgeClass(): string
    {
        return match (strtolower($this->status)) {
            'idle'        => 'bg-success',
            'inuse', 'busy', 'ringing' => 'bg-warning text-dark',
            'unavailable' => 'bg-danger',
            default       => 'bg-secondary',
        };
    }
}
