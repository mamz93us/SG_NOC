<?php

/*
|--------------------------------------------------------------------------
| Branch Health Scoring
|--------------------------------------------------------------------------
|
| The 100-point branch score shown on /admin/noc and its per-branch drill-
| down. Owned end to end by App\Services\BranchHealth — nothing else should
| compute a branch health number.
|
| Two rules drive every threshold below:
|
|   1. Missing inventory is never health. A check with no configured items
|      returns `unknown` with zero points AND zero coverage — it does not
|      quietly award full marks.
|   2. Stale telemetry is never health. Every freshness window here is at
|      least 2x the cadence of the collector that fills it (see
|      routes/console.php), so one missed cycle cannot flip a branch to
|      unknown, but a dead collector will.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Point weights — these MUST sum to 100
    |----------------------------------------------------------------------
    |
    | voip 40 + network 45 + devices 15 = 100. There is a test asserting
    | exactly this; if you change a weight, change its sibling too.
    |
    */
    'weights' => [
        'voip' => [
            'ucm_reachable' => 10,
            'ucm_trunks' => 12,
            'voice_mesh' => 10,
            'mos_compliance' => 8,
        ],
        'network' => [
            'firewall_reachable' => 12,
            'gateway_reachable' => 11,
            'switch_reachability' => 8,
            'access_point_reachability' => 6,
            'firewall_alerts' => 8,
        ],
        'devices' => [
            'printer_reachability' => 5,
            'biometric_reachability' => 6,
            'printer_toner' => 4,
        ],
    ],

    'category_labels' => [
        'voip' => 'VoIP',
        'network' => 'Network',
        'devices' => 'Devices',
    ],

    /*
    |----------------------------------------------------------------------
    | Freshness windows, in minutes
    |----------------------------------------------------------------------
    |
    | An observation older than its window is treated as `unknown`, not as
    | healthy. The collector cadence each one is derived from is noted
    | alongside it.
    |
    | Voice mesh is deliberately absent: it reuses VoiceMeshPair::isStale()
    | and config('voice_mesh.stale_after_minutes') so there is exactly one
    | definition of "the prober has gone quiet".
    |
    */
    'freshness' => [
        'ucm' => 2,              // SyncUcmExtensionsJob — every 15s
        'ucm_trunk' => 2,        // same job
        'branch_tunnel' => 3,    // tunnel-health:watch — every 1m
        'isp_link' => 15,        // check-isp-sla — every 5m
        'monitored_host' => 3,   // check-host-ping — every 1m
        'access_point' => 15,    // access-points:ping — every 5m
        'printer_supply' => 30,  // poll-printer-snmp — every 5m

        // Meraki is UI-configurable (settings.meraki_polling_interval), so
        // the switch window is max(min, intervals x that setting).
        'meraki_switch_min' => 15,
        'meraki_switch_intervals' => 3,
    ],

    /*
    |----------------------------------------------------------------------
    | Call quality
    |----------------------------------------------------------------------
    |
    | A branch is judged on the share of its calls meeting MOS-LQ >= 4.3.
    | Quiet branches would otherwise swing wildly on two or three calls, so
    | below `min_samples` in the 24h window the lookback widens to 7 days.
    |
    */
    'mos' => [
        'threshold' => 4.3,
        'window_hours' => 24,
        'extended_window_days' => 7,
        'min_samples' => 5,
    ],

    /*
    |----------------------------------------------------------------------
    | Toner
    |----------------------------------------------------------------------
    |
    | A printer passes if ANY known toner supply is above this. Printers
    | with no fresh, known toner reading are `unknown`, not passing.
    |
    */
    'toner' => [
        'min_percent' => 30,
    ],

    /*
    |----------------------------------------------------------------------
    | Critical caps
    |----------------------------------------------------------------------
    |
    | Applied AFTER raw_total, and every cap whose condition is met is
    | reported in `cap_reasons` — the score is never silently altered. Caps
    | are applied with min() so the order they are listed in cannot change
    | the result.
    |
    */
    'caps' => [
        'all_firewalls_down' => 59,
        'critical_firewall_alert' => 79,
    ],

    // NocEvent source types that count as a critical firewall/Sophos alert.
    'critical_alert_source_types' => [
        'tunnel_down',
        'sophos_central_fw_disconnected',
        'sophos_central_alert',
        'firewall',
    ],

    // NocEvent modules that count, for events written by NocAlertEngine
    // (which sets module/entity_* but never source_type).
    'critical_alert_modules' => [
        'sophos',
        'vpn',
    ],

    /*
    |----------------------------------------------------------------------
    | Health states
    |----------------------------------------------------------------------
    |
    | Lower bound of each band, evaluated against the coverage-normalized
    | score (earned / evaluable) so a branch is not marked down for gear it
    | has not onboarded yet. Anything below `at_risk` is critical.
    |
    | Healthy is deliberately a high bar: on a 100-point scale where a single
    | dead switch costs 8, "nearly everything is fine" is not the same claim as
    | "fine", and the board is read by people deciding what to touch today.
    |
    */
    'status_thresholds' => [
        'healthy' => 95,
        'degraded' => 80,
        'at_risk' => 60,
    ],

    // Coverage-normalizing stops a branch being marked down for kit it has not
    // onboarded -- but taken to the extreme it would call a branch with one
    // measurable check "Excellent". Below this many measurable points out of
    // 100 the state is `unknown` instead: we genuinely do not know enough to
    // make a claim, and saying so is the entire point of tracking coverage.
    'min_coverage_for_status' => 50,

    // The all-branch score is recomputed at most this often. Invalidation is
    // by expiry alone. Bump the cache version when the scoring model changes —
    // the database cache store cannot be selectively flushed cheaply.
    'cache_ttl_seconds' => 60,
    'cache_key' => 'noc:branch_health:v1',
];
