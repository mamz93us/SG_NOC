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

];
