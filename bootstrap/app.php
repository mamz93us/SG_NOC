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

        // Exclude HR API routes from CSRF — they use X-HR-Api-Key header auth instead
        $middleware->validateCsrfTokens(except: [
            'api/hr/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
