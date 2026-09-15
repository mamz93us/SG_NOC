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

        // A PDF import rewrites `pages` — the whole translated document — after
        // every page. AiKnowledgeImportController logs the upload and the delete
        // by hand, and the article an import makes is audited like any other.
        App\Models\AiKnowledgeImport::class,

        // Bumped by every unanswered question and re-scored every 15 minutes.
        // Answering, dismissing and reopening are logged by hand.
        App\Models\AiKnowledgeGap::class,

        // Rewritten on every read of a website. AiWebSourceController logs adding,
        // changing and deleting a website by hand; the articles are audited as usual.
        App\Models\AiWebSource::class,
        App\Models\AiWebPage::class,

        // Oracle's vacation figures, re-imported wholesale from each export.
        // Every import is logged by hand with its counts (VacationImport keeps
        // them), and so is HR linking an Oracle number to an employee.
        App\Models\Vacation\VacationEmployee::class,
        App\Models\Vacation\VacationBalance::class,
        App\Models\Vacation\VacationAbsence::class,
        App\Models\Vacation\VacationImport::class,

        // Recruitment AI: every sync stamps the job and every screening rewrites
        // its row, CV text included — an audit copy would be the one place that
        // text sat unencrypted. RecruitmentAiController logs switching screening
        // on and off, criteria changes, re-screens and deleting a job's AI data.
        App\Models\Recruitment\RecruitmentJob::class,
        App\Models\Recruitment\RecruitmentScreening::class,

        // Discovery scratch data, replaced on each scan.
        App\Models\SnmpDiscoveredDevice::class,
        App\Models\DiscoveryResult::class,

        // Document archive. The records themselves — documents, their index
        // values, their files — ARE audited, because a person editing an index
        // value is exactly the kind of event this log exists for; the ArcMate
        // sync and the transfer worker wrap their bulk writes in
        // Auditor::withoutAuditing() instead of being excluded here, so a
        // 600,000-file backfill writes no audit rows while a human edit still
        // does. What is excluded is everything around them:
        //   - page text, which is the document's contents rather than an event
        //   - the access log, which is its own purpose-built audit table
        //   - the task queue, whose rows are a button press already logged
        App\Models\Archive\ArchiveFileText::class,
        App\Models\Archive\ArchiveAccessLog::class,
        App\Models\Archive\ArchiveTask::class,
        //   - the transfer's own run log, written every minute for nights on end
        App\Models\Archive\ArchiveTransferRun::class,
        //   - AI meters and queues: a batch rewrites its counters every minute,
        //     records one usage row per call, and creates proposals in the
        //     thousands. Starting or cancelling a batch is logged by hand, and
        //     APPROVING a proposal writes a real value, which is audited as the
        //     edit it is. ArchiveAiSettings is deliberately NOT here: changing
        //     a budget is a decision.
        App\Models\Archive\ArchiveAiBatch::class,
        App\Models\Archive\ArchiveAiProposal::class,
        App\Models\Archive\ArchiveAiUsage::class,
        //   - the capture inbox, whose ai_status and ai_suggestions the worker
        //     rewrites as it reads each arriving scan. The event worth recording
        //     is the FILING, and that is audited where it happens: as the
        //     document, its values and its files being created.
        App\Models\Archive\ArchiveInboxItem::class,
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

        // Stamped on an API key by every call it authenticates — Oracle pages
        // through the attendance API many times a day. Only the timestamp: a
        // key turning up from a new address (last_used_ip) is still logged.
        'last_used_at',

        // The queue for AI-assigned category and tags on knowledge articles
        // (ai:classify-articles). The category and tags it writes are still logged.
        'ai_classify',
        'ai_classified_at',
        'ai_classify_error',

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

        // Who may read other people's attendance from the home-portal assistant.
        'attendance_owner_added',
        'attendance_owner_changed',
        'attendance_owner_removed',

        // Who may use an AI feature that reads restricted data (AI ▸ AI Access).
        'ai_access_granted',
        'ai_access_revoked',
    ],

    'prune_chunk' => 5000,
];
