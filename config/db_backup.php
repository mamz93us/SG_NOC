<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MySQL database → Azure Blob backups
    |--------------------------------------------------------------------------
    |
    | The `db-backups:run` scheduled command dumps the application database
    | with mysqldump, gzips it, streams it to Azure Blob (the `azure_db_backups`
    | disk) and records the run in the `database_backups` table. The "Backup
    | Now" button on Admin → Server Status queues the same run via
    | RunDatabaseBackupJob (drained by the every-minute queue drainer — this
    | NOC runs no long-lived queue worker). Azure credentials come from the
    | Settings singleton / env exactly like the other azure_* disks.
    |
    */

    // Filesystem disk dumps are uploaded to (config/filesystems.php).
    'disk' => env('DB_BACKUP_DISK', 'azure_db_backups'),

    // Path to the mysqldump binary. Plain 'mysqldump' resolves via PATH on
    // the VPS; point this at the full path if PHP-FPM's PATH is stripped.
    'mysqldump_path' => env('DB_BACKUP_MYSQLDUMP', 'mysqldump'),

    // Hard cap on a single dump run, in seconds.
    'timeout' => max(60, (int) env('DB_BACKUP_TIMEOUT', 1800)),

    // When the daily scheduled backup runs (server time, HH:MM).
    'schedule_time' => env('DB_BACKUP_SCHEDULE_TIME', '01:30'),

    // Azure retention: days to keep dumps before `db-backups:prune` deletes
    // the blob (the history row survives with status=pruned). Leave empty to
    // keep dumps forever — off-site backups never silently auto-delete
    // unless you opt in.
    'retention_days' => is_numeric(env('DB_BACKUP_RETENTION_DAYS'))
        ? (int) env('DB_BACKUP_RETENTION_DAYS')
        : null,

];
