<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trail of dynamic branch WAN-IP changes reflected into the allowlist, for
 * troubleshooting "why did this branch drop off / get added" questions.
 */
class AgwIpHistory extends Model
{
    protected $table = 'agw_ip_history';

    public $timestamps = false;

    protected $fillable = [
        'branch',
        'old_ip',
        'new_ip',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];
}
