<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WebSocket URL (public-facing, after Nginx proxy)
    |--------------------------------------------------------------------------
    | The URL the browser will connect to. With the Nginx proxy in place,
    | this is wss://<your-domain>/ws/telnet — no extra port needed.
    |
    | For local/dev without Nginx: ws://127.0.0.1:8765
    */
    'ws_url' => env('TELNET_WS_URL', 'wss://noc.samirgroup.net/ws/telnet'),

    /*
    |--------------------------------------------------------------------------
    | Internal secret shared with the Node.js proxy for token validation.
    | Set TELNET_INTERNAL_SECRET in .env (any random string).
    */
    'internal_secret' => env('TELNET_INTERNAL_SECRET', 'changeme'),

    /*
    |--------------------------------------------------------------------------
    | Token lifetime (minutes) — how long before a generated session token
    | expires if the terminal page is not loaded.
    */
    'token_ttl' => env('TELNET_TOKEN_TTL', 5),

    /*
    |--------------------------------------------------------------------------
    | Default Telnet port
    */
    'default_port' => 23,
];
