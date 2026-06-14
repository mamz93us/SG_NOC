<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Dedicated private disk for uploads that must never be served directly
        // (printer driver blobs, future documentation attachments). Same
        // filesystem as `local` but flagged as non-servable to make the intent
        // explicit at the call site. Access must go through a controller that
        // enforces auth + permission checks.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Azure Blob storage for offboarding backups (mailbox PST, OneDrive zip, laptop archive).
        // Credentials default to env, but AppServiceProvider re-reads them from Setting
        // on every Storage::disk('azure_offboarding') call so admins can configure them
        // via the Settings UI without redeploying.
        'azure_offboarding' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups'),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'offboarding/',
            'throw' => false,
        ],

        // Azure Blob storage for ad-hoc AvePoint backups (mailbox / OneDrive) requested
        // from the AvePoint admin module. Same container as offboarding, but uses an
        // 'avepoint/' prefix so the two contexts are clearly separated.
        'azure_avepoint' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups'),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'avepoint/',
            'throw' => false,
        ],

        // Azure Blob storage for course completion certificates. Files are uploaded
        // by marketing portal admins and served via tokenised public links to the
        // employee. Same container as offboarding, separated by 'certificates/'
        // prefix.
        'azure_certificates' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups'),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'certificates/',
            'throw' => false,
        ],

        // Azure Blob storage for bulk Teamtailor CV exports — one zip per job,
        // built by the teamtailor:process-cv-exports command and served back to
        // admins through an auth-gated download proxy (résumés are candidate PII,
        // never a public link). Same container as offboarding, 'teamtailor-resumes/'
        // prefix. Credentials are re-read from Setting on each call by the
        // AppServiceProvider azure driver, like the other azure_* disks.
        'azure_resumes' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups'),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'teamtailor-resumes/',
            'throw' => false,
        ],

        // Azure Blob storage for device/system backups pushed into the SFTP
        // inbox on the NOC (see deployment/sftp/ + config/sftp_backup.php). The
        // sftp-backups:sweep command streams each stable inbox file here and
        // then deletes the local copy. Same container as the other azure_*
        // disks by default, separated by the 'sftp-backups/' prefix; set
        // AZURE_BLOB_BACKUP_CONTAINER to isolate backups in their own container.
        'azure_backups' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_BACKUP_CONTAINER', env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups')),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'sftp-backups/',
            'throw' => false,
        ],

        // Azure Blob storage for the Download Center — ad-hoc files an admin puts
        // into the NOC (direct upload or fetched from a URL) to keep in cloud
        // storage and hand out via auth-gated NOC downloads or tokenised public
        // links. Same container as the other azure_* disks by default, separated
        // by the 'downloads/' prefix.
        'azure_downloads' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups'),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'downloads/',
            'throw' => false,
        ],

        // Azure Blob storage for the NOC's own MySQL dumps (config/db_backup.php).
        // The db-backups:run command (daily + "Backup Now" on Admin → Server
        // Status) gzips a mysqldump and streams it here. Same container as the
        // device backups by default, separated by the 'db-backups/' prefix.
        'azure_db_backups' => [
            'driver' => 'azure',
            'account' => env('AZURE_BLOB_ACCOUNT'),
            'key' => env('AZURE_BLOB_KEY'),
            'container' => env('AZURE_BLOB_BACKUP_CONTAINER', env('AZURE_BLOB_CONTAINER', 'noc-offboarding-backups')),
            'endpoint' => env('AZURE_BLOB_ENDPOINT_SUFFIX', 'core.windows.net'),
            'prefix' => 'db-backups/',
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
