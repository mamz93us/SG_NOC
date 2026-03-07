<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $fillable = [
        'event_type', 'recipient_type', 'recipient_role',
        'recipient_user_id', 'send_email', 'send_in_app', 'is_active',
    ];

    protected $casts = [
        'send_email' => 'boolean',
        'send_in_app' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public static function eventTypeLabels(): array
    {
        return [
            'approval_request'   => 'Approval Request',
            'approval_action'    => 'Approval Action',
            'workflow_complete'  => 'Workflow Completed',
            'workflow_failed'    => 'Workflow Failed',
            'system_alert'       => 'System Alert',
            'noc_alert'          => 'NOC Alert',
            'printer_maintenance'=> 'Printer Maintenance',
            'license_alert'      => 'License Alert',
        ];
    }
}
