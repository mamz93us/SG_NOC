<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Digital business card subdomain
    |--------------------------------------------------------------------------
    |
    | The employee card portal is served by this same Laravel app on its own
    | host (same code, same DB, domain-routed) — the same arrangement as the
    | email-marketing host in App\Support\Marketing.
    |
    | Employees sign in here with Microsoft SSO ONLY, and 2FA is deliberately
    | skipped on this host: it serves nothing but a person's own business card,
    | so there is no admin surface to protect. Everything else 404s here — see
    | App\Http\Middleware\EnforceVcardHostIsolation.
    |
    | This host is read at route-registration time, so after changing it run
    | `php artisan config:clear && php artisan route:clear`.
    |
    */

    'domain' => env('VCARD_DOMAIN', 'vcard.samirgroup.net'),

    /*
    | Set false to turn the subdomain off. Card links then fall back to whatever
    | host the request came in on (i.e. the NOC), which is the pre-subdomain
    | behaviour. The card routes on NOC keep working either way.
    */

    'enabled' => (bool) env('VCARD_ENABLED', true),

];
