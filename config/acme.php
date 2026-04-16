<?php

return [
    'directory_url'        => env('ACME_DIRECTORY', 'https://acme-v02.api.letsencrypt.org/directory'),
    'staging_url'          => 'https://acme-staging-v02.api.letsencrypt.org/directory',
    'email'                => env('ACME_EMAIL', 'admin@company.com'),
    'use_staging'          => env('ACME_STAGING', false),
    'dns_propagation_wait' => (int) env('ACME_DNS_WAIT', 30),
    'dns_poll_retries'     => (int) env('ACME_DNS_RETRIES', 12),
];
