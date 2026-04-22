<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $fillable = [
        'event_type', 'module', 'sensor_id', 'severity',
        'recipient_type', 'recipient_role',
        'recipient_user_id', 'send_email', 'send_in_app',
        'notify_telegram', 'notify_sms', 'notify_dashboard',
        'cooldown_minutes', 'is_active',
    ];

    protected $casts = [
        'send_email'       => 'boolean',
        'send_in_app'      => 'boolean',
        'notify_telegram'  => 'boolean',
        'notify_sms'       => 'boolean',
        'notify_dashboard' => 'boolean',
        'is_active'        => 'boolean',
        'cooldown_minutes' => 'integer',
    ];

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Match rules for a specific event type OR wildcard '*' rules.
     */
    public function scopeForEvent($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('event_type', $type)
              ->orWhere('event_type', '*');
        });
    }

    /**
     * Flat label map for every event_type string the app may emit.
     *
     * Keep this list complete — a rule whose event_type is missing here
     * still works but shows as the raw slug in the admin UI. Verified
     * against actual dispatch points in services/jobs/observers.
     */
    public static function eventTypeLabels(): array
    {
        return array_merge(...array_values(self::eventTypeGroups()));
    }

    /**
     * Same labels, grouped for optgroup-style <select> rendering in the
     * admin Rules UI. Each group is an associative array keyed by slug.
     */
    public static function eventTypeGroups(): array
    {
        return [
            'General' => [
                '*' => 'All Events (Wildcard)',
            ],
            'Workflow & Approvals' => [
                'approval_request'        => 'Approval Request',
                'approval_action'         => 'Approval Action',
                // `workflow_complete` (engine) and `workflow_completed`
                // (observer) are both emitted — keep both slugs so rules
                // targeting either keep working.
                'workflow_complete'       => 'Workflow Completed (engine)',
                'workflow_completed'      => 'Workflow Completed (status)',
                'workflow_failed'         => 'Workflow Failed',
                'workflow_rejected'       => 'Workflow Rejected',
                'workflow_cancelled'      => 'Workflow Cancelled',
                'workflow_notification'   => 'Workflow Custom Notification',
                'workflow_tasks_created'  => 'Workflow Setup Tasks Created',
                'workflow_all_tasks_done' => 'All Workflow Tasks Complete',
            ],
            'NOC & Monitoring' => [
                'noc_alert'           => 'NOC Alert (switch / VPN / UCM)',
                'system_alert'        => 'System Alert (licence / SLA)',
                'host_down'           => 'Host Down (SNMP / ping)',
                'supply_alert'        => 'Supply / Toner Alert',
                'printer_maintenance' => 'Printer Maintenance',
            ],
            'Assets, Licences & Expiries' => [
                'license_expiring'    => 'Licence Expiring Soon',
                'license_expired'     => 'Licence Expired',
                'license_alert'       => 'Licence Alert (generic)',
                'ssl_expiring'        => 'SSL Certificate Expiring',
                'ssl_expired'         => 'SSL Certificate Expired',
                'warranty_expiring'   => 'Warranty Expiring Soon',
                'warranty_expired'    => 'Warranty Expired',
                'isp_renewal'         => 'ISP Renewal Reminder',
            ],
            'Identity / Azure' => [
                'account_disabled' => 'Azure Account Disabled',
                'account_removed'  => 'Azure Account Removed',
            ],
        ];
    }
}
