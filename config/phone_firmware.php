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

];
