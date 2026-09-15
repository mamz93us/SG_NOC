<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Archive portal subdomain
    |--------------------------------------------------------------------------
    |
    | The document archive — the replacement for ArcMate 7.2 — is served by this
    | same Laravel app on its own host (same code, same DB, domain-routed), the
    | same arrangement as App\Support\VCard, App\Support\HrPortal,
    | App\Support\HomePortal and App\Support\Marketing.
    |
    | Sign-in here is Microsoft SSO ONLY and 2FA is deliberately skipped on this
    | host, exactly as on the HR portal. What makes that containable is
    | EnforceArchivePortalHostIsolation: on this host ONLY the archive routes
    | answer — /admin, the NOC, the phonebook and the other portals all 404 —
    | and every document, file and AI tool re-checks per-archive membership on
    | every single request (see Services\Archive\ArchiveAccess).
    |
    | This host is read at route-registration time, so after changing it run
    | `php artisan config:clear && php artisan route:clear`.
    |
    */

    'domain' => env('ARCHIVE_PORTAL_DOMAIN', 'archive.samirgroup.net'),

    /*
    | Set false to turn the subdomain off. The archive routes are registered only
    | under Route::domain(), so they stop answering entirely.
    */

    'enabled' => (bool) env('ARCHIVE_PORTAL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | ArcMate file share
    |--------------------------------------------------------------------------
    |
    | Where the ArcMate repository share is mounted on this host, read-only
    | (deployment/archive-portal/mount-arcmate.sh writes the cifs entry). This
    | is the FALLBACK location: a file is served from here until the transfer
    | worker has copied it to Azure and verified it, after which the file's own
    | row names the `azure_archive` disk instead.
    |
    | `path_prefix` is what ArcMate's tblMedia.arcPath values start with on the
    | Windows side, so a stored path can be translated to the mount. The live
    | value is on the source row; this is only the default for a new source.
    |
    */

    'mount_path' => env('ARCHIVE_MOUNT_PATH', '/mnt/arcmate'),

    'arcmate_path_prefix' => env('ARCHIVE_ARCMATE_PATH_PREFIX', 'D:\\ArcRepositories\\'),

    /*
    |--------------------------------------------------------------------------
    | Converted-file cache
    |--------------------------------------------------------------------------
    |
    | Multi-page TIFFs cannot be shown by a browser, so they are converted to
    | PDF with `tiff2pdf` on first view and kept here. Purely a cache: deleting
    | it costs one reconversion, never data. `archive:prune-cache` keeps it
    | under `cache_max_gb` by dropping the least recently used first.
    |
    | On the NOC the scheduler runs as azureuser while PHP-FPM runs as
    | www-data, and Flysystem creates private directories 0700 — so whichever
    | of the two writes this directory first must leave it readable by the
    | other. See the "scheduler runs as azureuser" gotcha in CLAUDE.md.
    |
    */

    'cache_path' => env('ARCHIVE_CACHE_PATH', 'archive-cache'),

    'cache_max_gb' => (float) env('ARCHIVE_CACHE_MAX_GB', 5),

    /*
    |--------------------------------------------------------------------------
    | Scan to e-mail
    |--------------------------------------------------------------------------
    |
    | The copiers that cannot do SFTP (the old Ricoh MP C3001/C3003 units) mail
    | their scans instead. They send to `u-<token>@` for a person's inbox or
    | `a-<token>@` for an archive's shared one, on this domain; the NOC's Postfix
    | accepts it only from `mynetworks`, pipes the raw message into `mail_spool`,
    | and `archive:ingest-mail` reads it from there.
    |
    | ROUTING IS BY RECIPIENT, never by sender. The relay rewrites every sender to
    | one SES-verified identity (deployment/smtp-relay/sender_canonical.regexp),
    | so the From address of an arriving scan identifies nothing at all.
    |
    | The domain is deliberately NOT a real mail domain with public MX: nothing
    | outside the office networks should be able to put a document into an inbox.
    |
    */

    'scan_mail_domain' => env('ARCHIVE_SCAN_MAIL_DOMAIN', 'scan.archive.samirgroup.net'),

    'mail_spool' => env('ARCHIVE_MAIL_SPOOL', '/var/spool/archive-mail/new'),

    /*
    | A scan bigger than this is refused rather than ingested — a 200 MB mail is
    | a misconfigured copier (600 dpi colour of a 40-page contract), not a
    | document somebody wants filed.
    */

    'max_scan_mb' => (int) env('ARCHIVE_MAX_SCAN_MB', 50),

    /*
    |--------------------------------------------------------------------------
    | Scan to folder
    |--------------------------------------------------------------------------
    |
    | The copiers that CAN do SFTP write into a per-destination SFTPGo account
    | whose home is a folder under this root, named after the account. The sweep
    | reuses the settled-file rule the backup sweeper learned: a file is only
    | taken once its mtime has stopped moving, because an in-progress transfer
    | keeps bumping it and half a scan is worse than a late one.
    |
    */

    'scan_folder_root' => env('ARCHIVE_SCAN_FOLDER_ROOT', '/srv/archive-scans'),

    'scan_folder_stability_seconds' => max(0, (int) env('ARCHIVE_SCAN_STABILITY_SECONDS', 90)),

];
