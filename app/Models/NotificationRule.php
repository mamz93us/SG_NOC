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
        'send_email' => 'boolean',
        'send_in_app' => 'boolean',
        'notify_telegram' => 'boolean',
        'notify_sms' => 'boolean',
        'notify_dashboard' => 'boolean',
        'is_active' => 'boolean',
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
     *
     * Ordered specific-first: the consumers treat this as a priority list and
     * take the first rule that matches each user ("first rule wins"). Without
     * an explicit order that came out in row order, so a catch-all '*' rule
     * could beat a rule written for the exact event purely because it was
     * created earlier.
     */
    public function scopeForEvent($query, string $type)
    {
        return $query
            ->where(function ($q) use ($type) {
                $q->where('event_type', $type)
                    ->orWhere('event_type', '*');
            })
            ->orderByRaw("CASE WHEN event_type = '*' THEN 1 ELSE 0 END")
            ->orderBy('id');
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
                'approval_request' => 'Approval Request',
                'approval_action' => 'Approval Action',
                // `workflow_complete` (engine) and `workflow_completed`
                // (observer) are both emitted — keep both slugs so rules
                // targeting either keep working.
                'workflow_complete' => 'Workflow Completed (engine)',
                'workflow_completed' => 'Workflow Completed (status)',
                'workflow_failed' => 'Workflow Failed',
                'workflow_rejected' => 'Workflow Rejected',
                'workflow_cancelled' => 'Workflow Cancelled',
                'workflow_notification' => 'Workflow Custom Notification',
                'workflow_tasks_created' => 'Workflow Setup Tasks Created',
                'workflow_all_tasks_done' => 'All Workflow Tasks Complete',
                'it_onboarding_summary' => 'IT Onboarding Summary (new-hire credentials)',
            ],
            'NOC & Monitoring' => [
                'noc_alert' => 'NOC Alert (switch offline / not syncing, UCM unreachable)',
                'system_alert' => 'System Alert (licence quota / SLA breach / overdue backup)',
                'host_down' => 'Host Down (SNMP / ping)',
                'supply_alert' => 'Supply / Toner Alert',
                'printer_maintenance' => 'Printer Maintenance',
                'cups_printer_offline' => 'CUPS Printer Offline',
                'backup_overdue' => 'Device Backup Overdue',
            ],
            'Branch Tunnels (VPN)' => [
                'tunnel_down' => 'Branch Tunnel Down',
                'tunnel_degraded' => 'Branch Tunnel Degraded (subnet unreachable)',
                'vpn_alert' => 'VPN Event Re-sent from the Events page',
            ],
            'Assets, Licences & Expiries' => [
                'license_expiring' => 'Licence Expiring Soon',
                'license_expired' => 'Licence Expired',
                'license_alert' => 'Licence Alert (generic)',
                'ssl_expiring' => 'SSL Certificate Expiring',
                'ssl_expired' => 'SSL Certificate Expired',
                'warranty_expiring' => 'Warranty Expiring Soon',
                'warranty_expired' => 'Warranty Expired',
                'isp_renewal' => 'ISP Renewal Reminder',
                'assets_alert' => 'Asset Event Re-sent from the Events page (warranty / printer)',
            ],
            'Identity / Azure' => [
                'account_disabled' => 'Azure Account Disabled',
                'account_removed' => 'Azure Account Removed',
            ],
            // NocController::resend() derives the event type from the event's
            // module — `printers` and `network`/`voip` are special-cased, every
            // other module becomes "{module}_alert". Without these slugs listed,
            // a re-sent Sophos or access-point event has no rule to match and
            // silently falls back to broadcasting to every admin.
            'Re-sent Events (by module)' => [
                'sophos_alert' => 'Sophos Central Alert / Firewall Disconnected',
                'access_point_alert' => 'Access Point Down',
                'ping_alert' => 'Ping / Host Availability',
                'branch_agent_alert' => 'Branch Agent Stale or DDNS Failure',
                'syslog_alert' => 'Syslog / Graylog Rule Match',
            ],
        ];
    }
}
