<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single hit on the it.samirgroup.net tracking landing page before the
 * visitor is forwarded to the IT ticketing app. One row per (non-bot) visit.
 */
class TicketVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'visited_at',
        'ip_address',
        'branch',
        'user_agent',
        'browser',
        'platform',
        'device_type',
        'referrer',
        'session_id',
        'is_unique_today',
        'country',
        'city',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'is_unique_today' => 'boolean',
    ];
}
