<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One request decision written by the noc-agw gateway. Read-only from the
 * NOC's perspective — surfaced in the Access Gateway → Audit view. Uses a
 * single `ts` column (no Eloquent timestamps).
 */
class AgwAudit extends Model
{
    protected $table = 'agw_audit';

    public $timestamps = false;

    protected $fillable = [
        'ts',
        'client_ip',
        'user_email',
        'user_name',
        'method',
        'path',
        'status',
        'decision',
        'reason',
        'user_agent',
        'latency_ms',
    ];

    protected $casts = [
        'ts' => 'datetime',
        'status' => 'integer',
        'latency_ms' => 'integer',
    ];

    public function scopeDenied($query)
    {
        return $query->whereIn('decision', ['deny_ip', 'deny_auth']);
    }
}
