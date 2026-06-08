<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchAgentWanIpHistory extends Model
{
    protected $table = 'branch_agent_wan_ip_history';

    protected $fillable = [
        'branch_agent_id',
        'ip',
        'previous_ip',
        'applied_dns',
        'applied_tunnel',
        'note',
        'changed_at',
    ];

    protected $casts = [
        'applied_dns' => 'boolean',
        'applied_tunnel' => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function branchAgent(): BelongsTo
    {
        return $this->belongsTo(BranchAgent::class);
    }
}
