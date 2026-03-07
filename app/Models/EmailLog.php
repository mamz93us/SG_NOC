<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'to_email', 'to_name', 'subject', 'notification_type',
        'notification_id', 'status', 'error_message', 'sent_at',
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
