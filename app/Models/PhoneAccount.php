<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneAccount extends Model
{
    protected $table = 'phone_accounts';

    protected $fillable = [
        'mac',
        'account_index',
        'sip_user_id',
        'sip_server',
        'account_status',
        'is_local',
        'fetched_at',
    ];

    protected $casts = [
        'is_local'   => 'boolean',
        'fetched_at' => 'datetime',
    ];
}
