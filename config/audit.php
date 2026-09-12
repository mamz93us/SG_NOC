<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic model auditing
    |--------------------------------------------------------------------------
    |
    | Every Eloquent model gets an AuditObserver unless it is excluded below.
    | Registration happens once at boot in AppServiceProvider by scanning
    | app/Models, so a new model is audited the day it is written rather than
    | the day somebody remembers to add an observer.
    |
    */

    'enabled' => env('AUDIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded models
    |--------------------------------------------------------------------------
    |
    | Telemetry and append-only firehoses. These are written by the scheduler
    | thousands of rows at a time; auditing them would write more rows than the
    | data itself, into the same MySQL that also runs the queue, the cache and
    | the sessions.
    |
    | Nothing here is a business record — every one of them is either a raw
    | sample, a derived roll-up that can be rebuilt, or the audit log itself.
    |
    */

    'exclude' => [
        // The log itself — auditing it would recurse.
        App\Models\ActivityLog::class,

        // SNMP / metrics firehose and its roll-ups.
        App\Models\SensorMetric::class,
        App\Models\SensorMetricHourly::class,
        App\Models\SensorMetricDaily::class,
        App\Models\HostCheck::class,
        App\Models\AvailabilitySnapshot::class,

        // Syslog ingest.
        App\Models\SyslogMessage::class,

        // Tunnel watchdog: one row per probe per minute.
        App\Models\TunnelHealthCheck::class,

        // Voice mesh sweeps.
        App\Models\VoiceMeshResult::class,
        App\Models\VoiceMeshRun::class,
        App\Models\VoiceQualityReport::class,

        // Attendance punches are raw device data and never edited; the derived
        // attendance_days table is rebuildable by design.
        App\Models\Attendance\AttendancePunch::class,
        App\Models\Attendance\AttendanceDay::class,

        // Switch / interface counters.
        App\Models\SwitchInterfaceStat::class,
        App\Models\SwitchDropStat::class,
        App\Models\SwitchQosStat::class,
        App\Models\SwitchCdpNeighbor::class,

        // Telephony caches, refreshed wholesale every few minutes.
        App\Models\UcmExtensionCache::class,
        App\Models\UcmTrunkCache::class,
        App\Models\UcmActiveCall::class,

        // DHCP lease table, re-imported in full.
        App\Models\DhcpLease::class,

        // Phone XML fetches: one row per phone per boot.
        App\Models\PhoneRequestLog::class,

        // Presence heartbeat, written by middleware on every page view.
        App\Models\AccessVisit::class,

        // Purpose-built audit tables that already record their own detail.
        App\Models\AgwAudit::class,
        App\Models\AgwIpHistory::class,
        App\Models\CredentialAccessLog::class,
        App\Models\AvepointDownloadAudit::class,
        App\Models\OffboardingDownloadAudit::class,
        App\Models\DeviceAccessLog::class,
        App\Models\EmailLog::class,
        App\Models\EmailMessage::class,
        App\Models\WhatsappLog::class,
        App\Models\IdentitySyncLog::class,
        App\Models\ServiceSyncLog::class,
        App\Models\VpnLog::class,
        App\Models\WorkflowLog::class,
        App\Models\SmtpRelayMessage::class,
        App\Models\SmtpRelayAttachment::class,
        App\Models\AdminLinkClick::class,
        App\Models\WallpaperCheckin::class,
        App\Models\PhoneFirmwareDownload::class,

        // AI transcripts: the conversation IS the record.
        App\Models\AiMessage::class,
        App\Models\AiKnowledgeChunk::class,

        // Discovery scratch data, replaced on each scan.
        App\Models\SnmpDiscoveredDevice::class,
        App\Models\DiscoveryResult::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redacted attributes
    |--------------------------------------------------------------------------
    |
    | An attribute whose name contains any of these is written as '[redacted]'.
    | Matched case-insensitively on the column name, so it covers the encrypted
    | casts too — the audit log must never become the one place a secret is
    | readable in plaintext.
    |
    | Values are never compared either: a changed secret logs
    | '[redacted]' => '[redacted]', which still records THAT it changed.
    |
    */

    'redact' => [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'credential',
        'passphrase',
        'salt',
        'signature',
        'two_factor',
        'remember_token',
        'access_key',
        'shared_key',
        'auth_pass',
        'priv_pass',
        'community',
        'jws',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attributes never worth a log line
    |--------------------------------------------------------------------------
    |
    | A change to only these columns is not an audit event. Without this, every
    | poll that stamps last_seen_at on a host writes an audit row.
    |
    */

    'ignore' => [
        'updated_at',
        'last_seen_at',
        'last_polled_at',
        'last_checked_at',
        'last_login_at',
        'last_activity_at',
        'remember_token',

        // Campaign counters, bumped by the send pipeline and the open/click and
        // SNS webhooks. Carried over from EmailMarketingActivityObserver, which
        // excluded them so a campaign's audit trail stayed about what a person
        // changed rather than how many opens arrived.
        'total_recipients',
        'total_sent',
        'total_delivered',
        'total_opens',
        'total_unique_opens',
        'total_clicks',
        'total_unique_clicks',
        'total_bounces',
        'total_complaints',
        'total_unsubscribes',
        'started_at',
        'sent_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | activity_logs is the compliance record, so the default is long. The prune
    | command (activity-log:prune, nightly) deletes in chunks so it never locks
    | the table — this is the same database the queue and sessions live in.
    |
    | Auth and permission events are kept longer than ordinary record edits:
    | they are what a privilege-escalation review actually reads.
    |
    */

    'retention_days' => env('AUDIT_RETENTION_DAYS', 730),

    'security_retention_days' => env('AUDIT_SECURITY_RETENTION_DAYS', 1825),

    'security_actions' => [
        'login',
        'logout',
        'login_failed',
        'login_lockout',
        'permission_denied',
        'two_factor_reset',
        'role_permissions_updated',
        'user_permissions_updated',
        'user_permissions_reset',
        'role_created',
        'role_updated',
        'role_deleted',
        'credential_revealed',
    ],

    'prune_chunk' => 5000,
];
