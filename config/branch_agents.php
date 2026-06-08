<?php

/*
|--------------------------------------------------------------------------
| Branch agent (sg-branch-agent) defaults
|--------------------------------------------------------------------------
|
| Cross-cutting defaults for the consolidated branch agent. Per-agent
| endpoints/tokens live in the `branch_agents` table (UI-managed). DNS zone
| and the config the agent pulls at enrollment are tuned here.
*/

return [
    // DDNS zone the agents' A records live under. Each agent gets
    // <dns_subdomain>.<dns_domain>, e.g. jed.branch.samirgroup.net.
    'dns_domain' => env('BRANCH_AGENTS_DNS_DOMAIN', 'branch.samirgroup.net'),
    'dns_record_ttl' => (int) env('BRANCH_AGENTS_DNS_TTL', 600),

    // One-time enrollment code lifetime (minutes).
    'enrollment_ttl_minutes' => (int) env('BRANCH_AGENTS_ENROLL_TTL', 60),

    // Heartbeat staleness thresholds (seconds since last heartbeat).
    'heartbeat_stale_seconds' => (int) env('BRANCH_AGENTS_STALE_SECONDS', 600),
    'heartbeat_down_seconds' => (int) env('BRANCH_AGENTS_DOWN_SECONDS', 1800),

    // Default agent runtime config served from GET /api/branch-agents/config.
    // The agent merges these over its local settings on each poll.
    'defaults' => [
        'log_retention_days' => (int) env('BRANCH_AGENTS_LOG_RETENTION_DAYS', 30),
        'log_max_total_gb' => (float) env('BRANCH_AGENTS_LOG_MAX_GB', 5),
        'snmp_poll_interval_s' => (int) env('BRANCH_AGENTS_SNMP_INTERVAL', 60),
        'discovery_interval_s' => (int) env('BRANCH_AGENTS_DISCOVERY_INTERVAL', 3600),
        'heartbeat_interval_s' => (int) env('BRANCH_AGENTS_HEARTBEAT_INTERVAL', 60),
        'ddns_check_interval_s' => (int) env('BRANCH_AGENTS_DDNS_INTERVAL', 300),
    ],
];
