<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firmware server paths
    |--------------------------------------------------------------------------
    |
    | What an admin types into the UCM Zero Config global policy. These are shown
    | on /admin/phones/firmware for copy-paste; nothing in the app fetches them.
    |
    | Grandstream wants a bare host (optionally with a path), no scheme — the
    | "Upgrade Via" dropdown next to it carries HTTP vs HTTPS.
    |
    | Prefer the internal path: it stays inside the branch tunnels and avoids TLS
    | entirely, which matters because older Grandstream firmware fails modern TLS
    | handshakes. Use the public one for a branch whose tunnel does not carry the
    | NOC subnet.
    |
    */

    'internal_url' => env('PHONE_FIRMWARE_INTERNAL_URL', '172.16.8.11'),

    'public_url' => env('PHONE_FIRMWARE_PUBLIC_URL', 'noc.samirgroup.net/fw'),

    /*
    |--------------------------------------------------------------------------
    | URL fetch
    |--------------------------------------------------------------------------
    |
    | Ceiling and timeout for `firmware:fetch-remote`, which pulls a vendor
    | package straight onto the NOC instead of making someone upload 150 MB
    | through the browser.
    |
    */

    'fetch_timeout' => (int) env('PHONE_FIRMWARE_FETCH_TIMEOUT', 1800),

    'max_fetch_bytes' => (int) env('PHONE_FIRMWARE_MAX_FETCH_BYTES', 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Download audit
    |--------------------------------------------------------------------------
    |
    | nginx serves the firmware images directly, so the app never sees a phone
    | fetching one. `firmware:ingest-log` tails the firmware vhost's access log
    | into `phone_firmware_downloads` — who took which image and when, plus the
    | 404s that reveal a filename nobody published.
    |
    | Reading /var/log/nginx requires the app user to be in the log's group
    | (`adm` on Ubuntu); deployment/firmware/setup.sh handles that. Absent or
    | unreadable, the ingest no-ops rather than erroring.
    |
    */

    'access_log' => env('PHONE_FIRMWARE_ACCESS_LOG', '/var/log/nginx/phone-firmware.access.log'),

    // Persisted {inode, offset} resume position, so each tick reads only new
    // bytes and log rotation is handled. App-owned directory.
    'state_path' => env('PHONE_FIRMWARE_STATE_PATH', storage_path('app/firmware/ingest-state.json')),

    // Cap bytes read per tick so one run stays bounded on a huge log.
    'max_bytes_per_run' => max(65536, (int) env('PHONE_FIRMWARE_MAX_BYTES_PER_RUN', 8 * 1024 * 1024)),

    // Days of download history to keep; null keeps it forever. Upgrades are
    // occasional and the rows are tiny, so this is generous.
    'retention_days' => is_numeric(env('PHONE_FIRMWARE_RETENTION_DAYS', 365))
        ? (int) env('PHONE_FIRMWARE_RETENTION_DAYS', 365)
        : null,

];
