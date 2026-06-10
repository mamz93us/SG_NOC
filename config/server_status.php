<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin → Server Status page
    |--------------------------------------------------------------------------
    |
    | systemd units checked on the host (via `systemctl is-active`). The
    | defaults match the NOC VPS stack; override with a comma-separated
    | SERVER_STATUS_SERVICES env value when units differ (e.g. another
    | php-fpm version). Checks degrade gracefully on hosts without systemd
    | (local Windows dev shows "unavailable" instead of erroring).
    |
    */

    'services' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SERVER_STATUS_SERVICES',
            'nginx,php8.3-fpm,mysql,supervisor,docker,sftpgo,rsyslog,strongswan,cron'
        ))
    ))),

    // Mount points whose filesystem types are noise, excluded from `df`.
    'df_exclude_types' => ['tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'efivarfs'],

];
