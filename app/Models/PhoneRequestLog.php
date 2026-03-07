<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneRequestLog extends Model
{
    use HasFactory;

    protected $table = 'phone_request_logs';

    protected $fillable = [
        'ip',
        'user_agent',
        'mac',
        'model',
    ];
}
