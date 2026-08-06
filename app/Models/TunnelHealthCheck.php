<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One watchdog cycle's verdict for one tunnel. Append-only history behind the
 * strip chart and the rolling uptime figure. No updated_at — these rows are
 * never modified, only inserted and pruned.
 */
class TunnelHealthCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'branch_tunnel_id',
        'state',
        'probes_up',
        'probes_total',
        'latency_ms',
        'checked_at',
    ];

    protected $casts = [
        'probes_up' => 'integer',
        'probes_total' => 'integer',
        'latency_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(BranchTunnel::class, 'branch_tunnel_id');
    }
}
