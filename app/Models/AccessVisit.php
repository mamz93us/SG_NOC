<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One access event on an authenticated SamirGroup app (NOC / EM / employee
 * Portal). Two kinds, distinguished by `event`:
 *   - login  : a successful SSO sign-in
 *   - access : a deduplicated "still active" heartbeat (~once per user/app/5min)
 */
class AccessVisit extends Model
{
    use HasFactory;

    public const APPS = ['noc', 'em', 'portal'];

    protected $fillable = [
        'occurred_at',
        'user_id',
        'user_name',
        'user_email',
        'app',
        'event',
        'path',
        'ip_address',
        'branch',
        'user_agent',
        'browser',
        'platform',
        'device_type',
        'session_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
