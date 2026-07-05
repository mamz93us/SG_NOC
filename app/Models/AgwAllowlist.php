<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A source IP/CIDR permitted to reach the legacy app through NOC-AGW.
 *
 * `dynamic` rows are maintained by the `agw:sync-allowlist` command from live
 * branch WAN IPs (branch_agents.wan_ip) and are keyed by `branch`. `manual`
 * rows are fixed ranges added by NOC staff and are never touched by the sync.
 */
class AgwAllowlist extends Model
{
    protected $table = 'agw_allowlist';

    protected $fillable = [
        'cidr',
        'branch',
        'source',
        'active',
        'note',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeDynamic($query)
    {
        return $query->where('source', 'dynamic');
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }
}
