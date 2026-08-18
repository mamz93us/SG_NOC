<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'to_number', 'to_name', 'notification_type', 'notification_id',
        'channel_mode', 'template_name', 'body', 'status',
        'wamid', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    public function statusBadgeClass(): string
    {
        return $this->status === 'sent' ? 'success' : 'danger';
    }
}
