<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee home portal subdomain
    |--------------------------------------------------------------------------
    |
    | The employee start page — the URL every company PC opens on — is served by
    | this same Laravel app on its own host (same code, same DB, domain-routed),
    | the same arrangement as App\Support\VCard, App\Support\HrPortal and
    | App\Support\Marketing.
    |
    | Sign-in here is Microsoft SSO ONLY and completes silently: an Entra-joined
    | Windows machine holds a Primary Refresh Token from Windows sign-in, so an
    | OAuth redirect carrying `prompt=none` comes back with a code and renders no
    | UI at all. The person is signed in as whoever they logged into Windows as,
    | without a click. See App\Http\Controllers\Home\HomeController.
    |
    | 2FA is deliberately skipped on this host. What makes that containable is
    | EnforceHomePortalHostIsolation: on this host ONLY the home portal routes
    | answer — /admin, the NOC, the phonebook and the other portals all 404.
    |
    | This host is read at route-registration time, so after changing it run
    | `php artisan config:clear && php artisan route:clear`.
    |
    */

    'domain' => env('HOME_PORTAL_DOMAIN', 'home.samirgroup.net'),

    /*
    | Set false to turn the subdomain off. The home routes are registered only
    | under Route::domain(), so they stop answering entirely.
    */

    'enabled' => (bool) env('HOME_PORTAL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Silent sign-in
    |--------------------------------------------------------------------------
    |
    | How long to stop re-attempting silent SSO after Entra says the person
    | cannot be signed in without interaction (a personal device, a browser with
    | no PRT, a private window).
    |
    | This cookie is the LOOP GUARD and is not optional: without it, a device
    | that can never satisfy `prompt=none` bounces against
    | login.microsoftonline.com on every single browser launch. Keep it long
    | enough to stop the loop and short enough that someone who has since signed
    | into Windows properly gets picked up again soon.
    |
    */

    'silent_retry_minutes' => (int) env('HOME_PORTAL_SILENT_RETRY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Payday countdown
    |--------------------------------------------------------------------------
    |
    | Drives the ring on the "My Payroll" card. This is a countdown only — no
    | salary figure is read, shown or stored anywhere. `day` is the day of the
    | month salaries land; set `last_working_day` to true to use the last
    | Sun–Thu of the month instead (Egypt/KSA working week).
    |
    */

    'payday' => [
        'day' => (int) env('HOME_PORTAL_PAYDAY_DAY', 25),
        'last_working_day' => (bool) env('HOME_PORTAL_PAYDAY_LAST_WORKING_DAY', false),
        // Where the "My Payroll" card sends people. Defaults to the HR portal.
        'url' => env('HOME_PORTAL_PAYROLL_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Core systems
    |--------------------------------------------------------------------------
    |
    | The big tiles at the top of the page. The LIST lives here rather than in the
    | database on purpose: config cannot be empty on first boot, and a
    | table-driven grid would render blank on day one — on the page every PC
    | opens on. The ADDRESSES live in settings (`home_portal_urls`) so changing
    | where a tile points is not a deploy; see App\Services\Home\CoreSystems.
    |
    | `key` drives the icon and any special behaviour — `servicedesk` opens the
    | ticket modal instead of navigating. An entry with no URL is hidden rather
    | than rendered as a dead tile, so leaving one blank switches it off.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Webmail
    |--------------------------------------------------------------------------
    |
    | Outlook on the web, in Quick access. Not a core-system tile: those are
    | line-of-business applications, and mail is the one thing everybody opens
    | every day regardless of what they do. Blank hides the card.
    |
    */

    'webmail_url' => env('HOME_PORTAL_WEBMAIL_URL', 'https://outlook.office.com/mail/'),

    'core_systems' => [
        [
            // Rendered by the combined "IT Service Desk" card in Quick access —
            // raising a ticket and tracking one belong together — so this entry
            // is skipped in the Core systems grid. It stays here because
            // CoreSystems::visible() and the Settings screen both read this
            // list, and because the card is only built when the tile exists.
            'key' => 'servicedesk',
            'name' => 'IT Service Desk',
            'meta' => 'Raise or track a support ticket',
            'url' => null, // opens the ticket modal
        ],
        [
            'key' => 'oracle',
            'name' => 'Oracle ERP',
            'meta' => 'Finance, procurement & operations',
            'url' => env('HOME_PORTAL_URL_ORACLE'),
        ],
        [
            'key' => 'salesforce',
            'name' => 'Salesforce',
            'meta' => 'Customer relationship management',
            'url' => env('HOME_PORTAL_URL_SALESFORCE'),
        ],
        [
            'key' => 'arcmate',
            'name' => 'ArcMate',
            'meta' => 'Access your ArcMate workspace',
            'url' => env('HOME_PORTAL_URL_ARCMATE', 'https://arcmate.samirgroup.net'),
        ],
    ],

];
