<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SG-NOC SMTP relay audit log
    |--------------------------------------------------------------------------
    |
    | The NOC runs a local Postfix smarthost so legacy Ricoh MFPs can scan-to-
    | email through Amazon SES (see deployment/smtp-relay/). Every message that
    | passes through it is logged by Postfix to `log_path`. The scheduled
    | `smtp-relay:ingest-log` command tails that file, parses one row per queue
    | id into `smtp_relay_messages` (+ `smtp_relay_attachments`), and the
    | /admin/smtp-relay page renders it: who sent what, size, attachments, and
    | whether SES accepted it.
    |
    | Reading /var/log/mail.log requires the app user (azureuser) to be in the
    | `adm` group — see deployment/smtp-relay/setup.sh / SMTP_RELAY_SETUP.md.
    |
    */

    // Postfix maillog to tail. Empty/absent (e.g. dev, Windows) → the ingest
    // command no-ops cleanly rather than erroring.
    'log_path' => env('SMTP_RELAY_LOG_PATH', '/var/log/mail.log'),

    // Where the ingester persists its {inode, offset} resume position, so it
    // only reads new lines each tick and survives log rotation. App-owned dir.
    'state_path' => env('SMTP_RELAY_STATE_PATH', storage_path('app/smtp-relay/ingest-state.json')),

    // How often the scheduler runs the ingest, in minutes.
    'ingest_interval' => max(1, (int) env('SMTP_RELAY_INGEST_INTERVAL', 1)),

    // Cap bytes read per tick so one run stays bounded on a huge/rotated log.
    'max_bytes_per_run' => max(65536, (int) env('SMTP_RELAY_MAX_BYTES_PER_RUN', 8 * 1024 * 1024)),

    // Days of relay history to keep. `smtp-relay:ingest-log --prune` (run daily)
    // deletes messages older than this. null = keep forever.
    'retention_days' => is_numeric(env('SMTP_RELAY_RETENTION_DAYS', 180))
        ? (int) env('SMTP_RELAY_RETENTION_DAYS', 180)
        : null,

    // The single SES-verified identity every sender is rewritten to (matches
    // deployment/smtp-relay/sender_canonical.regexp). Shown as context on the
    // page so operators know the "envelope from" seen by AWS.
    'rewritten_sender' => env('SMTP_RELAY_REWRITTEN_SENDER', 'scanner@samirgroup.com'),

];
