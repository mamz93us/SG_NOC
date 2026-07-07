<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A source IP/CIDR that is always denied by NOC-AGW. Takes precedence over the
 * allowlist and over the enforce-IP-ACL toggle — a blocked IP is blocked even
 * in allow-all mode.
 */
class AgwBlocklist extends Model
{
    protected $table = 'agw_blocklist';

    protected $fillable = [
        'cidr',
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
}
