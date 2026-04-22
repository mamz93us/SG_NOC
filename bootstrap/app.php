<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'        => \App\Http\Middleware\EnsureRole::class,
            'permission'  => \App\Http\Middleware\EnsurePermission::class,
            '2fa'         => \App\Http\Middleware\RequireTwoFactor::class,
            'hr.api_key'  => \App\Http\Middleware\HrApiKeyMiddleware::class,
            'internal.ip' => \App\Http\Middleware\InternalIpOnly::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\RequireTwoFactor::class);

        // Guests hitting the isolated /portal/* routes go to the portal's SSO-only
        // login page — not the admin login. Everyone else falls back to 'login'.
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->is('portal') || $request->is('portal/*')) {
                return route('portal.login');
            }
            return route('login');
        });

        // Trust reverse-proxy headers (X-Forwarded-Proto, etc.) so Laravel
        // detects HTTPS correctly behind the production proxy. Without this,
        // secure cookie handling and URL generation can be inconsistent,
        // which is a known cause of 419 "Page Expired" on form POSTs.
        $middleware->trustProxies(at: '*');

        // The 2FA challenge relies on an authenticated session and an OTP —
        // excluding it from CSRF avoids edge-case token-mismatch errors
        // that can occur after session regeneration during login.
        $middleware->validateCsrfTokens(except: [
            'two-factor-challenge',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
