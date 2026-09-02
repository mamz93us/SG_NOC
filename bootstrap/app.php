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
            'role' => \App\Http\Middleware\EnsureRole::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            '2fa' => \App\Http\Middleware\RequireTwoFactor::class,
            'hr.api_key' => \App\Http\Middleware\HrApiKeyMiddleware::class,
            'internal.ip' => \App\Http\Middleware\InternalIpOnly::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\RequireTwoFactor::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        // Records a deduplicated presence heartbeat for authenticated users on
        // the NOC / EM / Portal apps. No-op for guests, so public routes
        // (it.samirgroup.net forward, login pages) are unaffected.
        $middleware->appendToGroup('web', \App\Http\Middleware\LogAccessVisit::class);
        // The marketing subdomain serves ONLY the marketing portal (+ sign-in / 2FA /
        // recipient-facing email endpoints); everything else NOC 404s. Run this BEFORE
        // the auth middleware (via the priority list) so a NOC route on em 404s for
        // guests too, instead of auth redirecting them to the marketing login first.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnforceMarketingHostIsolation::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnforceMarketingHostIsolation::class,
        );
        // Same deal for the business-card subdomain: it serves cards and nothing
        // else. Runs before auth for the same reason — a NOC route probed on the
        // card host should 404, not bounce a guest through the card login first.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnforceVcardHostIsolation::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnforceVcardHostIsolation::class,
        );
        // And again for the HR portal subdomain: it serves the HR workspace and
        // nothing else. Before auth for the same reason — a NOC route probed on
        // the HR host should 404, not bounce a guest through the HR login first.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnforceHrPortalHostIsolation::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnforceHrPortalHostIsolation::class,
        );

        // And the employee home portal — the page every company PC opens on. It
        // serves the start page and its ticket endpoints, nothing else. Before
        // auth for the same reason as the others, and doubly so here: this host
        // is probed by whatever the fleet's browsers happen to load, so a NOC
        // route reached on it must 404 rather than start a login.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnforceHomePortalHostIsolation::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnforceHomePortalHostIsolation::class,
        );

        // Guests hitting the isolated /portal/* routes — or anything on the
        // marketing subdomain — go to the portal's SSO-only login page, not the
        // admin login. Everyone else falls back to 'login'.
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->getHost() === \App\Support\Marketing::domain()) {
                return route('portal.marketing.login');
            }
            if (\App\Support\VCard::isHost($request)) {
                return route('vcard.login');
            }
            if (\App\Support\HrPortal::isHost($request)) {
                return route('portal.hr.login');
            }
            if (\App\Support\HomePortal::isHost($request)) {
                return route('home.login');
            }
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
        // Machine-to-machine webhooks (Graylog) authenticate by shared
        // secret in their own header — they have no CSRF token to send.
        $middleware->validateCsrfTokens(except: [
            'two-factor-challenge',
            'api/graylog/webhook',
            'api/backup/upload-hook',
            'api/branch-config/*',
            'api/branch-agents/*',
            'api/voice-mesh/*',
            'api/wallpapers/checkin',
            'api/sns/email-events',
            'email/unsubscribe/*',
            // The telnet-proxy POSTs a finished deploy run's transcript and exit
            // code here. It has no session and no token, so CSRF would 419 it and
            // the run would hang in `running` until the reaper called it a
            // timeout. Authenticated by internal.ip + X-Telnet-Secret instead.
            'internal/deploy-run/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
