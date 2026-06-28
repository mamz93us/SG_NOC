<?php

/*
|--------------------------------------------------------------------------
| IT Ticket Portal — tracking proxy (it.samirgroup.net)
|--------------------------------------------------------------------------
|
| it.samirgroup.net points at this NOC app. Every hit is logged for
| analytics, then the visitor is forwarded to the real ticketing backend
| (an Oracle ADF/JSF app we do NOT modify — we only sit in front of it).
|
| The destination URL comes ONLY from here (env-driven). It is never taken
| from the request, so the forward can't be turned into an open redirect.
|
*/

return [

    // Host that serves the tracked landing page. The domain route in
    // routes/web.php is only registered when this is non-empty.
    'host' => env('TICKET_TRACKING_HOST', 'it.samirgroup.net'),

    // Where visitors are sent after logging. NEVER overridable per-request.
    'destination_url' => env(
        'TICKET_DESTINATION_URL',
        'https://sgprd.samirgroup.com/AssistantApp/faces/LoginPage.jsf'
    ),

    // redirect → HTTP 302 to destination (default; long URL shows in address bar).
    // proxy    → best-effort reverse proxy so it.samirgroup.net stays in the bar.
    //            EXPERIMENTAL: ADF/JSF is stateful — see TicketForwardController.
    'forward_mode' => env('TICKET_FORWARD_MODE', 'redirect'),

    // Landing page (it.samirgroup.net root). When true, the root shows a branded
    // landing page with the web + mobile app links; the "Open Web App" button
    // routes through /go (which logs the click, then redirects). When false, the
    // root just does the log+redirect directly. Only /go logs — never the
    // landing render — so bots/CT-scanners that load the page aren't counted.
    'landing_enabled' => (bool) env('TICKET_LANDING_ENABLED', true),

    // Optional logo override for the landing page (a full URL or a path under
    // public/). Leave unset to use the NOC company logo from Settings; if that's
    // also unset, the landing falls back to an inline SVG re-creation.
    'logo_url' => env('TICKET_LANDING_LOGO'),

    // Mobile app store links, by region. Edit here — surfaced on the landing page.
    'apps' => [
        'egypt' => [
            'android' => 'https://play.google.com/store/apps/details?id=io.samirgroup.ticketingapp.egypt',
            'ios' => 'https://apps.apple.com/eg/app/samir-assistant-egypt/id6761456415',
        ],
        'ksa' => [
            'android' => 'https://play.google.com/store/apps/details?id=io.samirgroup.ticketingapp',
            'ios' => 'https://apps.apple.com/us/app/samir-assistant/id6760613750',
        ],
    ],

    // Write the visit via a queued job instead of inline. NOTE: production runs
    // no dedicated queue worker (scheduler-as-worker), so a queued visit is only
    // persisted on the next queue drain. The inline insert is a single indexed
    // write and is effectively instant — keep async off unless you add a worker.
    'async_logging' => (bool) env('TICKET_ASYNC_LOGGING', false),

    // Privacy: mask the last octet (IPv4) / last 80 bits (IPv6) before storing.
    'anonymize_ip' => (bool) env('ANALYTICS_ANONYMIZE_IP', false),

    // Bots / uptime monitors are still forwarded, but excluded from analytics
    // (no row is written). Matched case-insensitively against the UA string.
    'ignore_bots' => (bool) env('TICKET_IGNORE_BOTS', true),
    'bot_user_agents' => [
        'bot', 'crawl', 'spider', 'slurp', 'monitor', 'uptime', 'pingdom',
        'headless', 'curl', 'wget', 'python-requests', 'go-http-client',
        'postman', 'statuscake', 'datadog', 'newrelic', 'zabbix',
    ],

    // Cookie used to distinguish unique visitors from repeat hits.
    'session_cookie' => env('TICKET_SESSION_COOKIE', 'tv_sid'),
    'session_cookie_days' => (int) env('TICKET_SESSION_COOKIE_DAYS', 365),

    // Optional GeoIP enrichment. Pluggable: point `resolver` at a class with
    // `public function resolve(string $ip): array` returning ['country'=>?, 'city'=>?].
    // Left disabled by default; logging skips it cleanly when off.
    'geoip' => [
        'enabled' => (bool) env('TICKET_GEOIP_ENABLED', false),
        'resolver' => env('TICKET_GEOIP_RESOLVER'), // e.g. \App\Services\Ticketing\MaxMindGeoIpResolver::class
    ],

    /*
    |----------------------------------------------------------------------
    | CIDR → branch map  (edit me)
    |----------------------------------------------------------------------
    | Maps office subnets to branch names. BranchResolver returns the first
    | matching branch, or 'unknown'. Both IPv4 and IPv6 CIDRs are supported.
    | These are PLACEHOLDERS — replace with each branch's real WAN/LAN ranges
    | (the same 10.x subnets reachable over the IPsec tunnels).
    */
    'branch_cidrs' => [
        'Jeddah' => ['10.10.0.0/16'],
        'Riyadh' => ['10.20.0.0/16'],
        'Al-Khobar' => ['10.30.0.0/16'],
        'Abha' => ['10.40.0.0/16'],
        'Cairo' => ['10.50.0.0/16'],
    ],
];
