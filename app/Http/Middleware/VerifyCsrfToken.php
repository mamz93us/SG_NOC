<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * These routes use their own authentication mechanism (API key / IP restriction)
     * so they don't need CSRF protection:
     *
     *  - api/hr/*         → X-HR-Api-Key header auth
     *  - admin/browser/*  → internal proxy, session+permission protected
     *  - internal/*       → localhost-only + X-Telnet-Secret header
     */
    protected $except = [
        'api/hr/*',
        'admin/browser/fetch',
        'internal/*',
        'api/graylog/webhook',
    ];
}
