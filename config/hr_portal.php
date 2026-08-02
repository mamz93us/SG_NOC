<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HR portal subdomain
    |--------------------------------------------------------------------------
    |
    | The HR workspace is served by this same Laravel app on its own host (same
    | code, same DB, domain-routed) — the same arrangement as the business-card
    | host in App\Support\VCard and the marketing host in App\Support\Marketing.
    |
    | HR signs in here with Microsoft SSO ONLY, and 2FA is deliberately skipped
    | on this host (owner's decision). What makes that containable is
    | EnforceHrPortalHostIsolation: on this host ONLY the HR workspace routes
    | answer — /admin, the NOC, the phonebook and the rest of the portal all
    | 404. Access to each HR form is still gated by permission, so the host
    | split is UX and blast-radius, not the security boundary.
    |
    | This host is read at route-registration time, so after changing it run
    | `php artisan config:clear && php artisan route:clear`.
    |
    */

    'domain' => env('HR_PORTAL_DOMAIN', 'hr.samirgroup.net'),

    /*
    | Set false to turn the subdomain off. The HR routes then stop answering on
    | that host entirely (they are registered only under Route::domain()), so
    | leave this on unless you are decommissioning the portal.
    */

    'enabled' => (bool) env('HR_PORTAL_ENABLED', true),

];
