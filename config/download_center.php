<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Download Center
    |--------------------------------------------------------------------------
    |
    | Tunables for the URL-fetch worker (downloads:fetch-remote). Direct uploads
    | are bounded by PHP's upload_max_filesize / post_max_size instead.
    |
    */

    // Largest file the URL fetcher will accept, in bytes. Default 20 GB — big
    // enough for OS install ISOs (Windows Server, etc.). The fetched file is
    // streamed to a temp file on the data disk first, so this is bounded by free
    // disk, not RAM.
    'max_fetch_bytes' => (int) env('DOWNLOAD_CENTER_MAX_FETCH_BYTES', 20 * 1024 * 1024 * 1024),

    // HTTP timeout for a single URL fetch, in seconds. Default 1 hour to cover
    // multi-GB downloads on a slow upstream.
    'fetch_timeout' => (int) env('DOWNLOAD_CENTER_FETCH_TIMEOUT', 3600),

];
