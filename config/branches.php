<?php

/*
|--------------------------------------------------------------------------
| Branch log-collector defaults
|--------------------------------------------------------------------------
|
| Branch endpoints (host/port/token) are now managed via the UI at
| /admin/branches/log-collectors and persisted in the
| `branch_log_collectors` table. This config file only carries cross-
| cutting HTTP defaults that apply to all branches.
|
| (Earlier versions enumerated all 9 branches here. Those entries moved
| to the database — run the migration and add them through the UI.)
|
*/

return [

    'http' => [
        'timeout'         => (int) env('BRANCH_LOGS_TIMEOUT', 10),
        'connect_timeout' => (int) env('BRANCH_LOGS_CONNECT_TIMEOUT', 3),
        'verify_tls'      => (bool) env('BRANCH_LOGS_VERIFY_TLS', false), // off because IPsec
    ],

];
