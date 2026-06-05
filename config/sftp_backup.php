<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SFTP inbox → Azure Blob backup sweeper
    |--------------------------------------------------------------------------
    |
    | Network devices (firewalls, UCM, switches, …) push their own backup files
    | into per-device SFTPGo accounts on the NOC VM (see deployment/sftpgo/). The
    | `sftp-backups:sweep` scheduled command picks up each *stable* file, streams
    | it to Azure Blob (the `azure_backups` disk), records it in the
    | `sftp_backups` table, and — once the upload is verified — removes the local
    | copy so the VM disk never fills. Azure credentials are read from the
    | Settings singleton (Admin → Settings → Azure Blob) by the `azure` driver,
    | exactly like the other azure_* disks.
    |
    */

    // Absolute path swept for backups = SFTPGo's users_base_dir. Each device's
    // SFTPGo account home is a folder here named after the account's username, so
    // the first path segment of each file is the "source" = its BackupAccount.
    // (The legacy deployment/sftp inbox was /srv/sftp-backups/inbox.)
    'inbox_path' => env('SFTP_BACKUP_INBOX', '/srv/backups'),

    // Filesystem disk the swept files are uploaded to (config/filesystems.php).
    'disk' => env('SFTP_BACKUP_DISK', 'azure_backups'),

    // A file is only swept once its mtime is at least this many seconds in the
    // past. This is the guard against uploading a half-written push: an
    // in-progress transfer keeps bumping mtime, so we wait for it to settle.
    'stability_seconds' => max(0, (int) env('SFTP_BACKUP_STABILITY_SECONDS', 120)),

    // Delete the local file after a verified upload. Keep ON in production — the
    // inbox is staging only, and an un-pruned inbox is the classic way to fill a
    // VM disk. Turn OFF only for debugging.
    'delete_after_upload' => filter_var(env('SFTP_BACKUP_DELETE_AFTER_UPLOAD', true), FILTER_VALIDATE_BOOL),

    // How often the scheduler runs the sweep, in minutes.
    'sweep_interval' => max(1, (int) env('SFTP_BACKUP_SWEEP_INTERVAL', 5)),

    // Max files to upload in a single sweep run, so one tick stays bounded and a
    // big backlog can't overrun the next schedule. 0 = unlimited.
    'max_files_per_run' => max(0, (int) env('SFTP_BACKUP_MAX_FILES_PER_RUN', 25)),

    // Optional hard size guard, in bytes. Larger files are skipped and flagged
    // (status=skipped) rather than uploaded. null = no limit.
    'max_file_bytes' => is_numeric(env('SFTP_BACKUP_MAX_BYTES'))
        ? (int) env('SFTP_BACKUP_MAX_BYTES')
        : null,

    // Azure retention: days to keep blobs before `sftp-backups:prune` deletes
    // them. Leave empty to keep backups forever — the safe default, since
    // off-site backups should never silently auto-delete unless you opt in.
    'retention_days' => is_numeric(env('SFTP_BACKUP_RETENTION_DAYS'))
        ? (int) env('SFTP_BACKUP_RETENTION_DAYS')
        : null,

    // Dotfiles plus these suffixes are treated as in-progress/partial uploads
    // and skipped while sweeping (well-behaved clients upload to a temp name and
    // rename on completion).
    'ignore_suffixes' => ['.part', '.filepart', '.tmp', '.partial'],

];
