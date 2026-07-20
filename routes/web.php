<?php

use App\Http\Controllers\Admin\AccessoryController;
use App\Http\Controllers\Admin\AccessStatsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminLinkController;
use App\Http\Controllers\Admin\AlertRuleController;
use App\Http\Controllers\Admin\AllowedDomainController;
use App\Http\Controllers\Admin\AzureSyncController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\CupsPrinterController;
use App\Http\Controllers\Admin\DarkModeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DeviceImportController;
use App\Http\Controllers\Admin\DeviceMetricsController;
use App\Http\Controllers\Admin\DeviceModelController;
use App\Http\Controllers\Admin\DhcpLeaseController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\DnsAccountController;
use App\Http\Controllers\Admin\DnsDomainsController;
use App\Http\Controllers\Admin\DnsLookupController;
use App\Http\Controllers\Admin\DnsNameserversController;
use App\Http\Controllers\Admin\DnsRecordsController;
use App\Http\Controllers\Admin\DocumentationController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailMarketing\CampaignApprovalsController;
use App\Http\Controllers\Admin\EmailMarketing\EmailMarketingSettingsController;
use App\Http\Controllers\Admin\EmailMarketing\QuotaController as EmAdminQuotaController;
use App\Http\Controllers\Admin\EmailMarketing\SuppressionsController as EmAdminSuppressionsController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeItemController;
use App\Http\Controllers\Admin\ExtensionController;
use App\Http\Controllers\Admin\GdmsController;
use App\Http\Controllers\Admin\GdmsTemplateController;
use App\Http\Controllers\Admin\HrApiKeyController;
use App\Http\Controllers\Admin\IdentityController;
use App\Http\Controllers\Admin\IpamController;
use App\Http\Controllers\Admin\IpReservationController;
use App\Http\Controllers\Admin\IpScannerController;
use App\Http\Controllers\Admin\IspConnectionController;
use App\Http\Controllers\Admin\IspProviderController;
use App\Http\Controllers\Admin\IspReportController;
use App\Http\Controllers\Admin\ItamController;
use App\Http\Controllers\Admin\ItTaskController;
use App\Http\Controllers\Admin\LandlineController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\LicenseMonitorController;
use App\Http\Controllers\Admin\NetworkController;
use App\Http\Controllers\Admin\NetworkDiscoveryController;
use App\Http\Controllers\Admin\NocController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\NotificationRuleController;
use App\Http\Controllers\Admin\OracleHrImportController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\PhoneAutoAssignController;
use App\Http\Controllers\Admin\PhoneManagementController;
use App\Http\Controllers\Admin\PortMapController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrinterMaintenanceController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SlaController;
use App\Http\Controllers\Admin\SnmpMonitoringController;
use App\Http\Controllers\Admin\SophosFirewallController;
use App\Http\Controllers\Admin\SslCertificateController;
use App\Http\Controllers\Admin\SubdomainController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\TicketStatsController;
use App\Http\Controllers\Admin\TopologyController;
use App\Http\Controllers\Admin\TrunkController;
use App\Http\Controllers\Admin\TunnelHealthController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UcmServerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPermissionController;
use App\Http\Controllers\Admin\VpnHubController;
use App\Http\Controllers\Admin\WarrantyTrackerController;
use App\Http\Controllers\Admin\WorkersDashboardController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Admin\WorkflowTemplateController;
use App\Http\Controllers\Admin\WorkflowTriggerController;
use App\Http\Controllers\Api\DeviceLookupController;
use App\Http\Controllers\Api\HrGroupAssignmentController;
use App\Http\Controllers\Api\HrOffboardingController;
use App\Http\Controllers\Api\HrOnboardingController;
use App\Http\Controllers\Api\SnsEmailEventsController;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\PhonebookController;
use App\Http\Controllers\PhoneRequestLogController;
use App\Http\Controllers\Portal\EmailMarketing\CampaignAnalyticsController as EmCampaignAnalyticsController;
use App\Http\Controllers\Portal\EmailMarketing\CampaignsController as EmCampaignsController;
use App\Http\Controllers\Portal\EmailMarketing\DashboardController as EmDashboardController;
use App\Http\Controllers\Portal\EmailMarketing\FontsController as EmFontsController;
use App\Http\Controllers\Portal\EmailMarketing\IconsController as EmIconsController;
use App\Http\Controllers\Portal\EmailMarketing\ListsController as EmListsController;
use App\Http\Controllers\Portal\EmailMarketing\SegmentsController as EmSegmentsController;
use App\Http\Controllers\Portal\EmailMarketing\SubscribersController as EmSubscribersController;
use App\Http\Controllers\Portal\EmailMarketing\TagsController as EmTagsController;
use App\Http\Controllers\Portal\EmailMarketing\TemplatesController as EmTemplatesController;
use App\Http\Controllers\Portal\Training\CourseCertificatesController as EmCourseCertificatesController;
use App\Http\Controllers\Portal\Training\CoursesController as EmCoursesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\OptInConfirmController;
use App\Http\Controllers\Public\UnsubscribeController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\TicketForwardController;
use App\Http\Controllers\Training\PublicCertificateController;
use App\Support\Marketing;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// The NOC root route (welcome view) is intentionally declared *after* the
// marketing subdomain group below — see the note there. An unconstrained '/'
// registered here would shadow the domain-constrained marketing dashboard on em.

Route::get('/phonebook.xml', [PhonebookController::class, 'generate'])
    ->withoutMiddleware(['web'])
    ->name('phonebook.xml');

Route::get('/contacts', [PublicContactController::class, 'index'])
    ->name('public.contacts');

Route::get('/contacts/print', [PublicContactController::class, 'print'])
    ->name('public.contacts.print');
// Compact print layout (landscape)
Route::get('/contacts/print-compact', [PhonebookController::class, 'printCompact'])->name('public.contacts.print.compact');

// Public documentation (only docs marked as public by admin)
Route::get('/documentation', [\App\Http\Controllers\Admin\DocumentationController::class, 'publicIndex'])->name('public.documentation.index');
Route::get('/documentation/{filename}', [\App\Http\Controllers\Admin\DocumentationController::class, 'publicShow'])->name('public.documentation.show');

// Employee digital business cards
// View + vCard are public (shareable via card_token). The Apple Wallet pass download
// requires a logged-in session (any authenticated NOC/portal user).
Route::get('/card/{token}', [\App\Http\Controllers\EmployeeCardController::class, 'show'])->name('employee.card.show');
Route::get('/card/{token}/vcard', [\App\Http\Controllers\EmployeeCardController::class, 'vcard'])->name('employee.card.vcard');
Route::get('/card/{token}/wallet', [\App\Http\Controllers\EmployeeCardController::class, 'walletPass'])
    ->middleware('auth')
    ->name('employee.card.wallet');
/*
|--------------------------------------------------------------------------
| Microsoft SSO
|--------------------------------------------------------------------------
*/

Route::get('/auth/microsoft', [MicrosoftController::class, 'redirect'])
    ->name('auth.microsoft');
Route::get('/auth/microsoft/callback', [MicrosoftController::class, 'callback']);

// ─── Remote Browser Portal (isolated user-facing app) ──────────
// Lives outside /admin so browser users never see admin chrome.
Route::prefix('portal')->name('portal.')->group(function () {

    // SSO-only login page
    Route::get('/login', function () {
        if (auth()->check()) {
            return redirect()->route('portal.index');
        }

        return view('auth.portal-login');
    })->name('login');

    // Portal logout
    Route::post('/logout', function (\Illuminate\Http\Request $request) {
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    })->name('logout');

    // Shared portal routes — any authenticated user can see their own hub, profile, and assets.
    // Individual tiles on the hub are permission-gated with @can(...).
    Route::middleware(['auth', 'throttle:60,1'])
        ->group(function () {
            // Portal hub (new default — tiles for all user apps)
            Route::get('/', [\App\Http\Controllers\Portal\PortalHubController::class, 'index'])->name('index');

            // My Profile (employee data from Azure + edit-with-approval flow)
            Route::get('/profile', [\App\Http\Controllers\Portal\MyProfileController::class, 'index'])->name('profile');
            Route::post('/profile/edit-request', [\App\Http\Controllers\Portal\MyProfileController::class, 'submitEditRequest'])->name('profile.edit-request');

            // My Assets (read-only view of everything assigned to this employee)
            Route::get('/assets', [\App\Http\Controllers\Portal\MyAssetsController::class, 'index'])->name('assets');
        });

    // Remote Browser — requires explicit permission.
    Route::middleware(['auth', 'permission:view-browser-portal', 'throttle:60,1'])
        ->group(function () {
            Route::get('/browser', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'index'])->name('browser');
            Route::post('/browser', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'store'])->name('store');
            Route::post('/browser/heartbeat', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'heartbeat'])->name('heartbeat');
            Route::get('/browser/history', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'history'])->name('history');
            Route::get('/browser/{sessionId}', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'show'])->name('show')
                ->where('sessionId', '[a-z0-9]{12}');
            Route::delete('/browser/{sessionId}', [\App\Http\Controllers\Admin\BrowserPortal\BrowserSessionController::class, 'destroy'])->name('destroy')
                ->where('sessionId', '[a-z0-9]{12}');
        });

    // HR onboarding (portal) — gated by submit-hr-onboarding permission.
    // Does NOT require view-browser-portal so a dedicated HR user can access this page only.
    Route::middleware(['auth', 'permission:submit-hr-onboarding', 'throttle:60,1'])
        ->prefix('hr/onboarding')->name('hr.onboarding.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Portal\HrOnboardingController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Portal\HrOnboardingController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Portal\HrOnboardingController::class, 'store'])->name('store');
        });
});

// ──────────────────────────────────────────────────────────────────
// Marketing subdomain — GUEST auth (login page + logout). No auth middleware so
// guests can reach the SSO login; every other route on em requires auth.
// ──────────────────────────────────────────────────────────────────
Route::domain(Marketing::domain())->group(function () {
    Route::get('/login', function () {
        if (auth()->check()) {
            return redirect()->route('portal.marketing.dashboard');
        }

        return view('auth.marketing-login');
    })->name('portal.marketing.login');

    Route::post('/logout', function (\Illuminate\Http\Request $request) {
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.marketing.login');
    })->name('portal.marketing.logout');

    // Authenticated users who lack `view-email-marketing` land here (EnsurePermission
    // routes them in on the marketing host). NO permission middleware — otherwise it
    // would loop back through EnsurePermission. Authed but access-less; never the NOC.
    Route::middleware('auth')->get('/no-access', function () {
        return view('auth.marketing-no-access');
    })->name('portal.marketing.no-access');

    // Public contest forms on the marketing host (no auth — access is gated by the
    // per-recipient token in the link). Lets employees open em.samirgroup.net/forms/{slug}
    // without a NOC login. Declared on the marketing domain so it resolves on `em`.
    Route::get('/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'show'])
        ->name('portal.marketing.form.show');
    Route::post('/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'submit'])
        ->middleware('throttle:20,1')->name('portal.marketing.form.submit');
});

// ──────────────────────────────────────────────────────────────────
// Email Marketing portal — ISOLATED on its own subdomain (em.samirgroup.net by
// default; configurable in Admin → Email Marketing → Settings, stored in the DB,
// nothing in .env). Served at the subdomain ROOT. Route names stay
// `portal.marketing.*` so every existing route('portal.marketing.*') reference
// keeps working unchanged. The host is resolved from settings (App\Support\Marketing)
// with a safe fallback, so this is robust during fresh installs / route:cache.
// Admin-side controls (SES creds, suppressions, senders) stay on NOC.
// ──────────────────────────────────────────────────────────────────
Route::domain(Marketing::domain())
    ->middleware(['auth', 'permission:view-email-marketing', 'throttle:120,1'])
    ->name('portal.marketing.')
    ->group(function () {
        Route::get('/', [EmDashboardController::class, 'index'])->name('dashboard');

        Route::resource('lists', EmListsController::class);

        // Subscribers + import — import routes must precede the resource binding for /import
        Route::get('subscribers/import', [EmSubscribersController::class, 'importForm'])->name('subscribers.import.form');
        Route::get('subscribers/import/template', [EmSubscribersController::class, 'importTemplate'])->name('subscribers.import.template');
        Route::post('subscribers/import/map', [EmSubscribersController::class, 'importMap'])->name('subscribers.import.map');
        Route::post('subscribers/import', [EmSubscribersController::class, 'importStore'])->name('subscribers.import.store');
        Route::resource('subscribers', EmSubscribersController::class);

        Route::resource('tags', EmTagsController::class)->except(['show']);
        Route::resource('segments', EmSegmentsController::class);
        Route::resource('templates', EmTemplatesController::class);

        Route::post('campaigns/{campaign}/send-now', [EmCampaignsController::class, 'sendNow'])->name('campaigns.send-now');
        Route::post('campaigns/{campaign}/schedule', [EmCampaignsController::class, 'schedule'])->name('campaigns.schedule');
        Route::post('campaigns/{campaign}/pause', [EmCampaignsController::class, 'pause'])->name('campaigns.pause');
        Route::post('campaigns/{campaign}/duplicate', [EmCampaignsController::class, 'duplicate'])->name('campaigns.duplicate');
        Route::post('campaigns/{campaign}/archive', [EmCampaignsController::class, 'archive'])->name('campaigns.archive');
        Route::post('campaigns/{campaign}/test-send', [EmCampaignsController::class, 'testSend'])->name('campaigns.test-send');
        Route::post('campaigns/{campaign}/recall', [EmCampaignsController::class, 'recall'])->name('campaigns.recall');
        // Concrete `benchmark` route before the resource binding so it isn't swallowed by {campaign}
        Route::get('campaigns/benchmark', [\App\Http\Controllers\Portal\EmailMarketing\CampaignBenchmarkController::class, 'show'])->name('campaigns.benchmark');
        Route::get('campaigns/{campaign}/analytics', [EmCampaignAnalyticsController::class, 'show'])->name('campaigns.analytics');
        Route::get('campaigns/{campaign}/analytics/recipient/{send}', [EmCampaignAnalyticsController::class, 'recipient'])->name('campaigns.analytics.recipient');
        Route::resource('campaigns', EmCampaignsController::class);

        // Templates duplicate + archive
        Route::post('templates/{template}/duplicate', [EmTemplatesController::class, 'duplicate'])->name('templates.duplicate');
        Route::post('templates/{template}/archive', [EmTemplatesController::class, 'archive'])->name('templates.archive');

        // SAMIR icon library + custom fonts (used by the Unlayer template editor)
        Route::resource('icons', EmIconsController::class)->except(['show']);
        Route::resource('fonts', EmFontsController::class)->except(['show']);

        // List export + manual add-subscriber
        Route::get('lists/{list}/export', [EmListsController::class, 'export'])->name('lists.export');
        Route::post('lists/{list}/sync', [EmListsController::class, 'sync'])->name('lists.sync');
        Route::post('lists/{list}/subscribers', [EmListsController::class, 'addSubscriber'])->name('lists.add-subscriber');
        Route::post('lists/{list}/attach', [EmListsController::class, 'attachExisting'])->name('lists.attach-existing');

        // Courses + per-employee completion certificates.
        // Concrete paths come before wildcards so `/create` and
        // `/employees/search` aren't swallowed by `{course}`.
        $view = ['permission:view-courses'];
        $manage = ['permission:manage-courses'];

        Route::get('courses', [EmCoursesController::class, 'index'])->middleware($view)->name('courses.index');
        Route::get('courses/create', [EmCoursesController::class, 'create'])->middleware($manage)->name('courses.create');
        Route::post('courses', [EmCoursesController::class, 'store'])->middleware($manage)->name('courses.store');
        Route::get('courses/employees/search', [EmCourseCertificatesController::class, 'employeeSearch'])
            ->middleware($view)->name('courses.employees.search');

        Route::get('courses/{course}/edit', [EmCoursesController::class, 'edit'])->middleware($manage)->name('courses.edit');
        Route::put('courses/{course}', [EmCoursesController::class, 'update'])->middleware($manage)->name('courses.update');
        Route::delete('courses/{course}', [EmCoursesController::class, 'destroy'])->middleware($manage)->name('courses.destroy');

        Route::get('courses/{course}/upload', [EmCourseCertificatesController::class, 'uploadForm'])->middleware($manage)->name('courses.upload.form');
        Route::post('courses/{course}/upload', [EmCourseCertificatesController::class, 'uploadStore'])->middleware($manage)->name('courses.upload.store');

        Route::post('courses/{course}/certificates/{certificate}/relink',
            [EmCourseCertificatesController::class, 'relink'])->middleware($manage)->name('courses.certificates.relink');
        Route::delete('courses/{course}/certificates/{certificate}',
            [EmCourseCertificatesController::class, 'destroy'])->middleware($manage)->name('courses.certificates.destroy');

        Route::get('courses/{course}/send', [EmCourseCertificatesController::class, 'sendForm'])->middleware($manage)->name('courses.send.form');
        Route::post('courses/{course}/send', [EmCourseCertificatesController::class, 'sendStore'])->middleware($manage)->name('courses.send.store');

        // Wildcard show MUST be last — it would otherwise swallow `create` etc.
        Route::get('courses/{course}', [EmCoursesController::class, 'show'])->middleware($view)->name('courses.show');

        // World Cup "Guess the Score" contests — self-service, no NOC/admin access needed.
        // Concrete `create` and `export`/`toggle` declared around the {form} wildcard.
        $wc = \App\Http\Controllers\Portal\EmailMarketing\WorldCupContestController::class;
        Route::get('contests', [$wc, 'index'])->name('contests.index');
        Route::get('contests/create', [$wc, 'create'])->name('contests.create');
        Route::post('contests', [$wc, 'store'])->name('contests.store');
        Route::get('contests/{form}/export', [$wc, 'export'])->name('contests.export');
        Route::post('contests/{form}/toggle', [$wc, 'toggle'])->name('contests.toggle');
        Route::post('contests/{form}/test-link', [$wc, 'testLink'])->name('contests.test-link');
        Route::post('contests/{form}/appearance', [$wc, 'updateAppearance'])->name('contests.appearance');
        Route::delete('contests/{form}/submissions/{submission}', [$wc, 'destroySubmission'])->name('contests.submissions.destroy');
        Route::get('contests/{form}', [$wc, 'show'])->name('contests.show');
    });

// ──────────────────────────────────────────────────────────────────
// IT Ticket Portal (it.samirgroup.net)
//   /     → branded landing page (web + mobile app links). Render only — the
//           landing is NOT logged, so bots/CT-scanners aren't counted.
//   /go   → logs the click-through, then forwards to the ticketing app.
// Destination + host come from config/ticket_tracking.php, never the request.
// The host-bound '/' MUST be declared before the unconstrained NOC root below
// so it.samirgroup.net/ resolves here instead of the welcome page. '/go' is
// host-independent so it also serves as a stable, testable entry point.
// ──────────────────────────────────────────────────────────────────
if ($ticketHost = config('ticket_tracking.host')) {
    Route::domain($ticketHost)->group(function () {
        Route::get('/', [TicketForwardController::class, 'landing'])->name('ticket.landing');
    });
}
Route::get('/go', [TicketForwardController::class, 'forward'])->name('ticket.go');

// IT Ticket Portal analytics — dashboard + CSV export (Admin menu, manage-settings).
Route::middleware(['auth', 'permission:manage-settings'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::get('ticket-stats', [TicketStatsController::class, 'index'])->name('ticket-stats.index');
        Route::get('ticket-stats/export', [TicketStatsController::class, 'export'])->name('ticket-stats.export');
    });

// JSON summary for reuse by other NOC widgets (auth-gated, path per spec).
Route::middleware(['auth', 'permission:manage-settings'])
    ->get('api/ticket-stats', [TicketStatsController::class, 'data'])->name('api.ticket-stats');

// Access Analytics — who's signing in to / using NOC, EM and the Portal.
// Gated by view-activity-logs (access-audit data, alongside the Audit Log).
Route::middleware(['auth', 'permission:view-activity-logs'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::get('access-stats', [AccessStatsController::class, 'index'])->name('access-stats.index');
        Route::get('access-stats/export', [AccessStatsController::class, 'export'])->name('access-stats.export');
    });
Route::middleware(['auth', 'permission:view-activity-logs'])
    ->get('api/access-stats', [AccessStatsController::class, 'data'])->name('api.access-stats');

// NOC public root. Declared AFTER the marketing domain group so a request to
// em.samirgroup.net/ matches the domain-constrained marketing dashboard first;
// every other host falls through to the welcome page.
Route::get('/', function () {
    return view('welcome');
});

// ──────────────────────────────────────────────────────────────────
// Public email marketing endpoints (no auth — signed URLs / SNS)
// ──────────────────────────────────────────────────────────────────
Route::get('email/unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('email.unsubscribe.show');
Route::post('email/unsubscribe/{token}', [UnsubscribeController::class, 'confirm'])->name('email.unsubscribe.confirm');
Route::get('email/opt-in/{token}', [OptInConfirmController::class, 'confirm'])->name('email.opt-in.confirm');

// Public template preview — signed URL, no auth. Used to share design drafts
// with stakeholders. Link expires after 7 days; regenerate from the editor.
Route::get('email/template-preview/{template}', [EmTemplatesController::class, 'publicPreview'])
    ->name('email.template.preview');

// Public tokenised access to course completion certificates. The token is the
// only credential; links never expire by design.
Route::get('certificates/{token}', [PublicCertificateController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('certificates.show');
Route::get('certificates/{token}/file', [PublicCertificateController::class, 'stream'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('certificates.download');

// SNS event webhook — auth'd by AWS message signature, CSRF-excepted in bootstrap/app.php
Route::post('api/sns/email-events', [SnsEmailEventsController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.sns.email-events');

// Legacy /browser redirect for any old bookmarks
Route::redirect('/browser', '/portal/login');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Profile (change password modal)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('/admin/profile/password', [ProfileController::class, 'updatePassword'])
        ->name('admin.profile.password');
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Protected by auth + per-route permissions)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {

    // Home page — welcome screen (KPIs, recent activity, branch health, quick actions),
    // rendered inside the classic admin top-nav layout. Portal-only roles bounce out.
    Route::get('/', function () {
        if (auth()->user()?->usesPortal()) {
            return redirect()->route('portal.index');
        }

        return app(DashboardController::class)->index();
    })->name('dashboard');

    // Phonebook & UCM Overview — the previous /admin landing, now its own page.
    Route::get('phonebook-overview', [DashboardController::class, 'phonebookOverview'])
        ->name('phonebook.overview');

    // Dark mode toggle
    Route::post('toggle-dark-mode', [DarkModeController::class, 'toggle'])
        ->name('toggle-dark-mode');

    // Welcome-screen customizable quick links (per-user)
    Route::post('quick-links', [\App\Http\Controllers\Admin\UserQuickLinkController::class, 'store'])
        ->name('quick-links.store');
    Route::delete('quick-links/{quickLink}', [\App\Http\Controllers\Admin\UserQuickLinkController::class, 'destroy'])
        ->name('quick-links.destroy');

    // Two-Factor Authentication setup (authenticated users)
    Route::get('two-factor', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    // Design preview of the forced-enrolment screen — super_admin only, non-destructive.
    Route::get('two-factor/preview', [TwoFactorController::class, 'preview'])
        ->name('two-factor.preview');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->name('two-factor.confirm');
    Route::delete('two-factor', [TwoFactorController::class, 'disable'])
        ->name('two-factor.disable');

    // XML preview (all authenticated)
    Route::get('xml-preview', [PhonebookController::class, 'preview'])
        ->name('xml.preview');

    // ─── Branches (CRUD — also accessible from Settings › Locations) ──
    Route::middleware('permission:view-branches')->group(function () {
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
    });
    Route::middleware('permission:manage-branches')->group(function () {
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::patch('branches/{branch}', [BranchController::class, 'update']);
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });

    // ─── Contacts (VoIP menu) ─────────────────────────────────
    Route::middleware('permission:view-contacts')->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/create', [ContactController::class, 'create'])->name('contacts.create');
        Route::get('contacts/{contact}/edit', [ContactController::class, 'edit'])->name('contacts.edit');
        Route::post('contacts/check-duplicate', [ContactController::class, 'checkDuplicate'])
            ->name('contacts.check-duplicate');
        Route::get('contacts/search-light', [ContactController::class, 'searchLight'])
            ->name('contacts.search-light');
    });
    Route::middleware('permission:manage-contacts')->group(function () {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::patch('contacts/{contact}', [ContactController::class, 'update']);
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    });
    Route::middleware('permission:export-contacts')->group(function () {
        Route::get('contacts-export', [ContactController::class, 'export'])->name('contacts.export');
    });

    // ─── Activity Logs ────────────────────────────────────────
    Route::middleware('permission:view-activity-logs')->group(function () {
        Route::get('activity-logs', [ActivityLogController::class, 'index'])
            ->name('activity-logs');
    });

    // ─── Phone XML Logs ───────────────────────────────────────
    Route::middleware('permission:view-phone-logs')->group(function () {
        Route::get('phone-logs', [PhoneRequestLogController::class, 'index'])
            ->name('phone-logs.index');
    });
    Route::middleware('permission:sync-phone-logs')->group(function () {
        Route::post('phone-logs/sync', [PhoneRequestLogController::class, 'sync'])
            ->name('phone-logs.sync');
        Route::post('phone-logs/sync-unsynced', [PhoneRequestLogController::class, 'syncUnsynced'])
            ->name('phone-logs.sync-unsynced');
    });

    // ─── Extensions (VoIP menu) ───────────────────────────────
    Route::middleware('permission:view-extensions')->group(function () {
        Route::get('extensions', [ExtensionController::class, 'index'])
            ->name('extensions.index');
        Route::get('extensions/{extension}/details', [ExtensionController::class, 'details'])
            ->name('extensions.details');
        Route::get('extensions/{extension}/wave', [ExtensionController::class, 'wave'])
            ->name('extensions.wave');
    });
    Route::middleware('permission:manage-extensions')->group(function () {
        Route::post('extensions', [ExtensionController::class, 'store'])
            ->name('extensions.store');
        Route::put('extensions/{extension}', [ExtensionController::class, 'update'])
            ->name('extensions.update');
        Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])
            ->name('extensions.destroy');
    });

    // ─── VoIP Trunks ──────────────────────────────────────────
    Route::middleware('permission:view-trunks')->group(function () {
        Route::get('trunks', [TrunkController::class, 'index'])
            ->name('trunks.index');
    });

    // ─── Phone Management (GDMS) ──────────────────────────────
    Route::middleware('permission:view-phones')->group(function () {
        Route::get('phones', [PhoneManagementController::class, 'index'])
            ->name('phones.index');
        Route::get('phones/{mac}', [PhoneManagementController::class, 'show'])
            ->name('phones.show')->where('mac', '[0-9a-fA-F:\-\.]{12,17}');
    });
    Route::middleware('permission:manage-phones')->group(function () {
        Route::get('phones/create', [PhoneManagementController::class, 'create'])
            ->name('phones.create');
        Route::post('phones', [PhoneManagementController::class, 'store'])
            ->name('phones.store');
        Route::post('phones/{mac}/reboot', [PhoneManagementController::class, 'reboot'])
            ->name('phones.reboot')->where('mac', '[0-9a-fA-F:\-\.]{12,17}');
        // Assign-account, push-config and factory-reset are GDMS-console operations:
        // the GDMS OpenAPI exposes no account→device binding, device/config push,
        // or confirmed factory-reset taskType. See GDMS_PHONE_MANAGEMENT.md.
    });

    // ─── GDMS Config Templates (read-only list; edit/assign live in GDMS console) ──
    Route::middleware('permission:view-phones')->group(function () {
        Route::get('gdms/templates', [GdmsTemplateController::class, 'index'])
            ->name('gdms.templates.index');
    });
    Route::middleware('permission:manage-phones')->group(function () {
        Route::post('gdms/templates/sync', [GdmsTemplateController::class, 'sync'])
            ->name('gdms.templates.sync');
    });

    // ─── Settings ─────────────────────────────────────────────
    Route::middleware('permission:manage-settings')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])
            ->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])
            ->name('settings.update');
        Route::delete('settings/logo', [SettingsController::class, 'deleteLogo'])
            ->name('settings.delete-logo');
        Route::delete('settings/login-wallpaper', [SettingsController::class, 'deleteWallpaper'])
            ->name('settings.delete-wallpaper');
        Route::post('settings/sso', [SettingsController::class, 'updateSso'])
            ->name('settings.sso');
        Route::post('settings/meraki', [SettingsController::class, 'updateMeraki'])
            ->name('settings.meraki');
        Route::post('settings/graph', [SettingsController::class, 'updateGraph'])
            ->name('settings.graph');
        Route::post('settings/gdms', [SettingsController::class, 'updateGdms'])
            ->name('settings.gdms');
        Route::post('settings/teamtailor', [SettingsController::class, 'updateTeamtailor'])
            ->name('settings.teamtailor');
        Route::post('settings/avepoint', [SettingsController::class, 'updateAvePoint'])->name('settings.avepoint');
        Route::post('settings/azure-blob', [SettingsController::class, 'updateAzureBlob'])->name('settings.azure-blob');
        Route::post('settings/offboarding', [SettingsController::class, 'updateOffboarding'])->name('settings.offboarding');
        // Test-connection buttons live on the Settings page — accessible to any settings manager
        Route::post('settings/test-meraki', [NetworkController::class,  'testConnection'])->name('settings.test-meraki');
        Route::post('settings/test-graph', [IdentityController::class, 'testConnection'])->name('settings.test-graph');
        Route::post('settings/avepoint/test', [SettingsController::class, 'testAvePoint'])->name('settings.avepoint.test');
        Route::post('settings/azure-blob/test', [SettingsController::class, 'testAzureBlob'])->name('settings.azure-blob.test');
        Route::post('settings/sftpgo', [SettingsController::class, 'updateSftpgo'])->name('settings.sftpgo');
        Route::post('settings/sftpgo/test', [SettingsController::class, 'testSftpgo'])->name('settings.sftpgo.test');
        Route::post('settings/sophos-central', [SettingsController::class, 'updateSophosCentral'])->name('settings.sophos-central');
        Route::post('settings/sophos-central/test', [SettingsController::class, 'testSophosCentral'])->name('settings.sophos-central.test');
        Route::post('settings/employee-cards', [SettingsController::class, 'updateEmployeeCards'])->name('settings.employee-cards');

        // ── Sync Status Dashboard ────────────────────────────────────
        Route::get('sync-status', [\App\Http\Controllers\Admin\SyncStatusController::class, 'index'])->name('sync-status');
        Route::post('sync-status/intervals', [\App\Http\Controllers\Admin\SyncStatusController::class, 'updateIntervals'])->name('sync-status.intervals');
        Route::post('sync-status/trigger', [\App\Http\Controllers\Admin\SyncStatusController::class, 'triggerSync'])->name('sync-status.trigger');

        // ── Locations (all 4 tiers: branches, floors, racks, offices) ──
        Route::get('settings/locations', [SettingsController::class, 'locations'])->name('settings.locations');

        // Branches (modal-based, same page)
        Route::post('settings/branches', [BranchController::class, 'store'])->name('settings.branches.store');
        Route::put('settings/branches/{branch}', [BranchController::class, 'update'])->name('settings.branches.update');
        Route::delete('settings/branches/{branch}', [BranchController::class, 'destroy'])->name('settings.branches.destroy');

        // Floors (same CRUD as before, also accessible from settings)
        Route::post('settings/floors', [NetworkController::class, 'storeFloor'])->name('settings.floors.store');
        Route::put('settings/floors/{floor}', [NetworkController::class, 'updateFloor'])->name('settings.floors.update');
        Route::delete('settings/floors/{floor}', [NetworkController::class, 'destroyFloor'])->name('settings.floors.destroy');

        // Racks
        Route::post('settings/racks', [NetworkController::class, 'storeRack'])->name('settings.racks.store');
        Route::put('settings/racks/{rack}', [NetworkController::class, 'updateRack'])->name('settings.racks.update');
        Route::delete('settings/racks/{rack}', [NetworkController::class, 'destroyRack'])->name('settings.racks.destroy');

        // Offices (new tier)
        Route::post('settings/offices', [NetworkController::class, 'storeOffice'])->name('settings.offices.store');
        Route::put('settings/offices/{office}', [NetworkController::class, 'updateOffice'])->name('settings.offices.update');
        Route::delete('settings/offices/{office}', [NetworkController::class, 'destroyOffice'])->name('settings.offices.destroy');

        // ── Departments ──────────────────────────────────────────────
        Route::get('settings/departments', [SettingsController::class, 'departments'])->name('settings.departments');
        Route::post('settings/departments', [SettingsController::class, 'storeDepartment'])->name('settings.departments.store');
        Route::put('settings/departments/{department}', [SettingsController::class, 'updateDepartment'])->name('settings.departments.update');
        Route::delete('settings/departments/{department}', [SettingsController::class, 'destroyDepartment'])->name('settings.departments.destroy');

        // ── Asset Types ──────────────────────────────────────────────
        Route::get('settings/asset-types', [\App\Http\Controllers\Admin\AssetTypeController::class, 'index'])->name('settings.asset-types');
        Route::post('settings/asset-types', [\App\Http\Controllers\Admin\AssetTypeController::class, 'store'])->name('settings.asset-types.store');
        Route::put('settings/asset-types/{assetType}', [\App\Http\Controllers\Admin\AssetTypeController::class, 'update'])->name('settings.asset-types.update');
        Route::delete('settings/asset-types/{assetType}', [\App\Http\Controllers\Admin\AssetTypeController::class, 'destroy'])->name('settings.asset-types.destroy');
        Route::post('settings/asset-types/settings', [\App\Http\Controllers\Admin\AssetTypeController::class, 'updateSettings'])->name('settings.asset-types.settings');

        // ── SMTP / Outgoing Mail ──────────────────────────────────────
        Route::post('settings/cups', [SettingsController::class, 'updateCups'])->name('settings.cups');
        Route::post('settings/itam', [SettingsController::class, 'updateItam'])->name('settings.itam');
        Route::post('settings/ticketing', [SettingsController::class, 'updateTicketing'])->name('settings.ticketing');
        Route::post('settings/smtp', [SettingsController::class, 'updateSmtp'])->name('settings.smtp');
        Route::post('settings/test-smtp', [SettingsController::class, 'testSmtp'])->name('settings.test-smtp');

        // ── Allowed Domains ──────────────────────────────────────────
        Route::get('settings/domains', [\App\Http\Controllers\Admin\AllowedDomainController::class, 'index'])->name('settings.domains');
        Route::post('settings/domains', [\App\Http\Controllers\Admin\AllowedDomainController::class, 'store'])->name('settings.domains.store');
        Route::delete('settings/domains/{allowedDomain}', [\App\Http\Controllers\Admin\AllowedDomainController::class, 'destroy'])->name('settings.domains.destroy');
        Route::patch('settings/domains/{allowedDomain}/set-primary', [\App\Http\Controllers\Admin\AllowedDomainController::class, 'setPrimary'])->name('settings.domains.set-primary');
    });

    // ─── Access Gateway (NOC-AGW: fronts the legacy IIS app) ──
    Route::middleware('permission:manage-agw-allowlist')->group(function () {
        Route::get('access-gateway', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'index'])
            ->name('access-gateway.index');
        Route::post('access-gateway/allowlist', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'storeManual'])
            ->name('access-gateway.allowlist.store');
        Route::patch('access-gateway/allowlist/{entry}/toggle', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'toggle'])
            ->name('access-gateway.allowlist.toggle');
        Route::delete('access-gateway/allowlist/{entry}', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'destroyManual'])
            ->name('access-gateway.allowlist.destroy');
        Route::post('access-gateway/sync', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'syncNow'])
            ->name('access-gateway.sync');
        Route::post('access-gateway/blocklist', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'blocklistStore'])
            ->name('access-gateway.blocklist.store');
        Route::patch('access-gateway/blocklist/{entry}/toggle', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'blocklistToggle'])
            ->name('access-gateway.blocklist.toggle');
        Route::delete('access-gateway/blocklist/{entry}', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'blocklistDestroy'])
            ->name('access-gateway.blocklist.destroy');
    });
    Route::middleware('permission:manage-agw-settings')->group(function () {
        Route::post('access-gateway/settings', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'updateSettings'])
            ->name('access-gateway.settings');
    });
    Route::middleware('permission:view-agw-audit')->group(function () {
        Route::get('access-gateway/audit', [\App\Http\Controllers\Admin\AccessGatewayController::class, 'audit'])
            ->name('access-gateway.audit');
    });

    // ─── Server Status (host health + database backups) ──────
    Route::middleware('permission:view-server-status')->group(function () {
        Route::get('server-status', [\App\Http\Controllers\Admin\ServerStatusController::class, 'index'])
            ->name('server-status');
        Route::get('server-status/metrics', [\App\Http\Controllers\Admin\ServerStatusController::class, 'metrics'])
            ->name('server-status.metrics');
    });
    Route::middleware('permission:manage-server-status')->group(function () {
        Route::post('server-status/db-backups', [\App\Http\Controllers\Admin\ServerStatusController::class, 'backupNow'])
            ->name('server-status.db-backups.run');
        Route::get('server-status/db-backups/{databaseBackup}/download', [\App\Http\Controllers\Admin\ServerStatusController::class, 'downloadBackup'])
            ->name('server-status.db-backups.download');
        Route::delete('server-status/db-backups/{databaseBackup}', [\App\Http\Controllers\Admin\ServerStatusController::class, 'deleteBackup'])
            ->name('server-status.db-backups.destroy');
    });

    // ─── UCM Servers (managed from Settings page) ─────────────
    Route::middleware('permission:manage-settings')->group(function () {
        Route::post('ucm-servers', [UcmServerController::class, 'store'])
            ->name('ucm-servers.store');
        Route::put('ucm-servers/{ucmServer}', [UcmServerController::class, 'update'])
            ->name('ucm-servers.update');
        Route::delete('ucm-servers/{ucmServer}', [UcmServerController::class, 'destroy'])
            ->name('ucm-servers.destroy');
        Route::patch('ucm-servers/{ucmServer}/toggle', [UcmServerController::class, 'toggleActive'])
            ->name('ucm-servers.toggle');
    });

    // ─── User Management ──────────────────────────────────────
    Route::middleware('permission:manage-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])
            ->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])
            ->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->name('users.destroy');
        Route::post('users/{user}/reset-2fa', [UserController::class, 'resetTwoFactor'])
            ->name('users.reset-2fa');
    });

    // ─── GDMS UCM Status ──────────────────────────────────────
    Route::middleware('permission:view-extensions')->group(function () {
        Route::get('gdms/ucm', [GdmsController::class, 'ucmIndex'])
            ->name('gdms.ucm');
    });

    // ─── Role Permissions & Per-User Overrides ────────────────
    Route::middleware('permission:manage-permissions')->group(function () {
        Route::get('permissions', [PermissionsController::class, 'index'])
            ->name('permissions.index');
        Route::put('permissions', [PermissionsController::class, 'update'])
            ->name('permissions.update');

        Route::get('users/{user}/permissions', [UserPermissionController::class, 'edit'])
            ->name('users.permissions.edit');
        Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
            ->name('users.permissions.update');
        Route::delete('users/{user}/permissions', [UserPermissionController::class, 'reset'])
            ->name('users.permissions.reset');
    });

    // ─── Device Models ────────────────────────────────────────
    Route::middleware('permission:view-assets')->group(function () {
        Route::get('devices/models', [DeviceModelController::class, 'index'])->name('devices.models.index');
    });
    Route::middleware('permission:manage-assets')->group(function () {
        Route::post('devices/models', [DeviceModelController::class, 'store'])->name('devices.models.store');
        Route::put('devices/models/{deviceModel}', [DeviceModelController::class, 'update'])->name('devices.models.update');
        Route::delete('devices/models/{deviceModel}', [DeviceModelController::class, 'destroy'])->name('devices.models.destroy');
    });

    // ─── Devices (Assets) ─────────────────────────────────────
    Route::middleware('permission:view-assets')->group(function () {
        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::get('devices/create', [DeviceController::class, 'create'])->name('devices.create');
        Route::get('devices/scan', [DeviceController::class, 'scan'])->name('devices.scan');
        Route::get('devices/generate-code', [DeviceController::class, 'generateCode'])->name('devices.generate-code');
        Route::get('devices/dhcp-lookup', [DeviceController::class, 'dhcpLookup'])->name('devices.dhcp-lookup');
        Route::get('devices/warranty', [WarrantyTrackerController::class, 'index'])->name('devices.warranty');
        Route::get('devices/firmware', [DeviceController::class, 'firmware'])->name('devices.firmware');
        Route::get('devices/batch-create', [DeviceController::class, 'batchCreate'])->name('devices.batch-create');
        Route::get('devices/phone-auto-assign', [PhoneAutoAssignController::class, 'index'])->name('devices.phone-auto-assign');
        Route::get('devices/import', [DeviceImportController::class, 'showForm'])->name('devices.import');
        // Must register before devices/{device} or "mac-backfill" binds as a device id.
        Route::get('devices/mac-backfill', [DeviceController::class, 'macBackfill'])
            ->middleware('permission:manage-assets')->name('devices.mac-backfill');
        Route::get('devices/{device}/label', [DeviceController::class, 'label'])->name('devices.label');
        Route::get('devices/{device}/edit', [DeviceController::class, 'edit'])->name('devices.edit');
        Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    });
    Route::middleware('permission:manage-assets')->group(function () {
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::post('devices/batch-store', [DeviceController::class, 'batchStore'])->name('devices.batch-store');
        Route::put('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('devices/{device}/assign', [DeviceController::class, 'quickAssign'])->name('devices.assign');
        Route::post('devices/{device}/return', [DeviceController::class, 'quickReturn'])->name('devices.return');
        Route::post('devices/phone-auto-assign', [PhoneAutoAssignController::class, 'store'])->name('devices.phone-auto-assign.store');
        Route::post('devices/phone-auto-assign/create-assets', [PhoneAutoAssignController::class, 'createAssets'])->name('devices.phone-auto-assign.create-assets');
        Route::post('devices/phone-auto-assign/manual-assign', [PhoneAutoAssignController::class, 'manualAssign'])->name('devices.phone-auto-assign.manual-assign');
        Route::post('devices/import/preview', [DeviceImportController::class, 'preview'])->name('devices.import.preview');
        Route::post('devices/import/apply', [DeviceImportController::class, 'apply'])->name('devices.import.apply');
        Route::post('devices/import/manual', [DeviceImportController::class, 'manualStore'])->name('devices.import.manual');
        Route::post('devices/import/batch', [DeviceImportController::class, 'batchStore'])->name('devices.import.batch');
        Route::post('devices/mac-backfill', [DeviceController::class, 'macBackfillApply'])->name('devices.mac-backfill.apply');
    });

    // ─── Device Backups (SFTPGo accounts + status) ────────────
    Route::middleware('permission:view-backups')->group(function () {
        Route::get('backups', [\App\Http\Controllers\Admin\BackupAccountController::class, 'index'])->name('backups.index');
        Route::get('backups/create', [\App\Http\Controllers\Admin\BackupAccountController::class, 'create'])->name('backups.create');
        Route::get('backups/{backupAccount}', [\App\Http\Controllers\Admin\BackupAccountController::class, 'show'])->name('backups.show');
        Route::get('backups/{backupAccount}/edit', [\App\Http\Controllers\Admin\BackupAccountController::class, 'edit'])->name('backups.edit');
    });
    Route::middleware('permission:manage-backups')->group(function () {
        Route::post('backups', [\App\Http\Controllers\Admin\BackupAccountController::class, 'store'])->name('backups.store');
        Route::put('backups/{backupAccount}', [\App\Http\Controllers\Admin\BackupAccountController::class, 'update'])->name('backups.update');
        Route::post('backups/{backupAccount}/reveal', [\App\Http\Controllers\Admin\BackupAccountController::class, 'reveal'])->name('backups.reveal');
        Route::post('backups/{backupAccount}/rotate', [\App\Http\Controllers\Admin\BackupAccountController::class, 'rotate'])->name('backups.rotate');
        Route::get('backups/file/{sftpBackup}/download', [\App\Http\Controllers\Admin\BackupAccountController::class, 'downloadFile'])->name('backups.download');
        Route::delete('backups/{backupAccount}', [\App\Http\Controllers\Admin\BackupAccountController::class, 'destroy'])->name('backups.destroy');
        Route::delete('backups/{backupAccount}/purge', [\App\Http\Controllers\Admin\BackupAccountController::class, 'purge'])->name('backups.purge');
    });

    // ─── Download Center (ad-hoc files → Azure Blob) ──────────
    Route::middleware('permission:view-downloads')->group(function () {
        Route::get('downloads', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'index'])->name('downloads.index');
        Route::get('downloads/{download}/status', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'status'])->name('downloads.status');
        Route::get('downloads/{download}/file', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'downloadFile'])->name('downloads.download');
    });
    Route::middleware('permission:manage-downloads')->group(function () {
        Route::post('downloads', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'storeUpload'])->name('downloads.store');
        Route::post('downloads/url', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'storeUrl'])->name('downloads.store-url');
        Route::post('downloads/{download}/retry', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'retry'])->name('downloads.retry');
        Route::post('downloads/{download}/public', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'togglePublic'])->name('downloads.public');
        Route::post('downloads/{download}/rotate', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'rotateToken'])->name('downloads.rotate');
        Route::delete('downloads/{download}', [\App\Http\Controllers\Admin\DownloadCenterController::class, 'destroy'])->name('downloads.destroy');
    });

    // ─── Device Web Proxy & SSH ───────────────────────────────────────────
    Route::middleware(['permission:manage-devices', 'throttle:60,1'])->group(function () {
        // Web proxy — browse device management UI through the NOC server
        Route::get('devices/{device}/browse',
            [\App\Http\Controllers\Admin\DeviceProxyController::class, 'browse'])
            ->name('devices.browse');
        Route::any('devices/{device}/proxy/{path?}',
            [\App\Http\Controllers\Admin\DeviceProxyController::class, 'proxy'])
            ->name('devices.proxy')
            ->where('path', '.*');

        // SSH terminal — tied to a device record
        Route::get('devices/{device}/ssh',
            [\App\Http\Controllers\Admin\DeviceSshController::class, 'connect'])
            ->name('devices.ssh.connect');
        Route::post('devices/{device}/ssh',
            [\App\Http\Controllers\Admin\DeviceSshController::class, 'terminal'])
            ->name('devices.ssh.terminal');
        Route::post('devices/{device}/ssh/sessions/{session}/disconnect',
            [\App\Http\Controllers\Admin\DeviceSshController::class, 'disconnect'])
            ->name('devices.ssh.disconnect');
    });

    // ─── Credentials (Password Vault) ─────────────────────────
    Route::middleware('permission:view-credentials')->group(function () {
        Route::get('credentials', [CredentialController::class, 'index'])->name('credentials.index');
        Route::get('credentials/generate', [CredentialController::class, 'generate'])->name('credentials.generate');
        Route::get('credentials/create', [CredentialController::class, 'create'])->name('credentials.create');
        Route::get('credentials/{credential}/edit', [CredentialController::class, 'edit'])->name('credentials.edit');
        // Reveal password: POST (no browser history, permission-gated in controller, rate-limited)
        Route::post('credentials/{credential}/reveal', [CredentialController::class, 'reveal'])
            ->middleware('throttle:20,1')
            ->name('credentials.reveal');
    });
    Route::middleware('permission:manage-credentials')->group(function () {
        Route::post('credentials', [CredentialController::class, 'store'])->name('credentials.store');
        Route::put('credentials/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
        Route::post('credentials/{credential}/log-copy', [CredentialController::class, 'logCopy'])->name('credentials.log-copy');
    });

    // ─── Printers ─────────────────────────────────────────────
    // IMPORTANT: printers/drivers MUST be registered before printers/{printer}
    // to avoid Laravel matching "drivers" as a printer ID.
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('printers/drivers', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'index'])->name('printers.drivers.index');
        Route::get('printers/drivers/create', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'create'])->name('printers.drivers.create');
        Route::get('printers/drivers/{printerDriver}/edit', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'edit'])->name('printers.drivers.edit');
        Route::get('printers/drivers/{printerDriver}/download', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'download'])->name('printers.drivers.download');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('printers/drivers', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'store'])->name('printers.drivers.store');
        Route::put('printers/drivers/{printerDriver}', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'update'])->name('printers.drivers.update');
        Route::delete('printers/drivers/{printerDriver}', [\App\Http\Controllers\Admin\PrinterDriverController::class, 'destroy'])->name('printers.drivers.destroy');
    });

    // ─── Printer Usage Report — MUST be registered before printers/{printer} wildcard ──
    Route::middleware('permission:view-printer-usage')->group(function () {
        Route::get('printers/usage',
            [\App\Http\Controllers\Admin\PrinterUsageReportController::class, 'index'])
            ->name('printers.usage');
        Route::post('printers/usage/snapshot',
            [\App\Http\Controllers\Admin\PrinterUsageReportController::class, 'snapshotNow'])
            ->name('printers.usage.snapshot');
        Route::post('printers/usage/backfill',
            [\App\Http\Controllers\Admin\PrinterUsageReportController::class, 'backfillHistory'])
            ->name('printers.usage.backfill');
    });
    // ─── Printer Alert Settings — MUST be registered before printers/{printer} wildcard ──
    Route::middleware('permission:manage-printer-alerts')->group(function () {
        Route::get('printers/branch-settings',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'index'])
            ->name('printers.branch.index');
        Route::get('printers/branch-settings/{branch}/edit',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'edit'])
            ->name('printers.branch.edit');
        Route::put('printers/branch-settings/{branch}',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'update'])
            ->name('printers.branch.update');
        Route::post('printers/branch-settings/{branch}/recipients',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'addRecipient'])
            ->name('printers.branch.recipients.add');
        Route::delete('printers/branch-settings/recipients/{recipient}',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'deleteRecipient'])
            ->name('printers.branch.recipients.delete');
        Route::post('printers/branch-settings/recipients/{recipient}/toggle',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'toggleRecipient'])
            ->name('printers.branch.recipients.toggle');
        Route::post('printers/branch-settings/{branch}/test',
            [\App\Http\Controllers\Admin\PrinterBranchSettingController::class, 'test'])
            ->name('printers.branch.test');
    });

    Route::middleware('permission:view-printers')->group(function () {
        // IMPORTANT: static segments (dashboard, create, unified, etc.) MUST come before {printer} wildcard
        Route::get('printers/dashboard', [PrinterController::class, 'dashboard'])->name('printers.dashboard');
        Route::get('printers/unified',
            [\App\Http\Controllers\Admin\UnifiedPrinterController::class, 'index'])
            ->name('printers.unified.index');
        Route::get('printers/unified/{printer}',
            [\App\Http\Controllers\Admin\UnifiedPrinterController::class, 'show'])
            ->name('printers.unified.show');
        Route::get('printers', [PrinterController::class, 'index'])->name('printers.index');
        Route::get('printers/create', [PrinterController::class, 'create'])->name('printers.create');
        // SNMP live dashboard — static segment, MUST be before the printers/{printer}
        // wildcard or it 404s (matched as a printer id by the show route).
        Route::get('printers/snmp-status', [PrinterController::class, 'snmpStatus'])->name('printers.snmp.status');
        Route::get('printers/{printer}/edit', [PrinterController::class, 'edit'])->name('printers.edit');
        Route::get('printers/{printer}', [PrinterController::class, 'show'])->name('printers.show');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('printers', [PrinterController::class, 'store'])->name('printers.store');
        // Network SNMP auto-discovery (creates printers) — keep before {printer} wildcards
        Route::post('printers/discover-scan', [PrinterController::class, 'discoverScan'])->name('printers.discover-scan');
        Route::post('printers/discover-sensors', [PrinterController::class, 'discoverSensors'])->name('printers.discover-sensors');
        Route::post('printers/toner-digest', [PrinterController::class, 'tonerDigest'])->name('printers.toner-digest');
        Route::put('printers/{printer}', [PrinterController::class, 'update'])->name('printers.update');
        Route::delete('printers/{printer}', [PrinterController::class, 'destroy'])->name('printers.destroy');
        // Manual employee assignment
        Route::post('printers/{printer}/assign', [PrinterController::class, 'assignEmployee'])->name('printers.assign');
        Route::delete('printers/{printer}/assign/{employee}', [PrinterController::class, 'unassignEmployee'])->name('printers.unassign');
    });

    // ─── Printer SNMP Dashboard ──────────────────────────────
    // NOTE: GET printers/snmp-status is registered above (before the
    // printers/{printer} wildcard) so it doesn't 404. Only the {printer}-scoped
    // and POST actions live here.
    Route::middleware('permission:view-printers')->group(function () {
        Route::post('printers/{printer}/snmp-poll', [PrinterController::class, 'snmpPoll'])->name('printers.snmp.poll');
        Route::post('printers/snmp-poll-all', [PrinterController::class, 'snmpPollAll'])->name('printers.snmp.poll-all');
        Route::post('printers/{printer}/snmp-toggle', [PrinterController::class, 'toggleSnmp'])->name('printers.snmp.toggle');
    });

    // ─── CUPS Print Manager ────────────────────────────────────
    Route::middleware('permission:view-print-manager')->group(function () {
        Route::get('print-manager', [CupsPrinterController::class, 'index'])->name('print-manager.index');
        Route::get('print-manager/create', [CupsPrinterController::class, 'create'])->name('print-manager.create');
        Route::get('print-manager/{cupsPrinter}', [CupsPrinterController::class, 'show'])->name('print-manager.show');
        Route::get('print-manager/{cupsPrinter}/edit', [CupsPrinterController::class, 'edit'])->name('print-manager.edit');
    });
    Route::middleware('permission:manage-print-manager')->group(function () {
        Route::post('print-manager', [CupsPrinterController::class, 'store'])->name('print-manager.store');
        Route::put('print-manager/{cupsPrinter}', [CupsPrinterController::class, 'update'])->name('print-manager.update');
        Route::delete('print-manager/{cupsPrinter}', [CupsPrinterController::class, 'destroy'])->name('print-manager.destroy');
        Route::post('print-manager/{cupsPrinter}/refresh', [CupsPrinterController::class, 'refreshStatus'])->name('print-manager.refresh');
        Route::post('print-manager/{cupsPrinter}/test', [CupsPrinterController::class, 'testPrint'])->name('print-manager.test');
        Route::post('print-manager/{cupsPrinter}/jobs/{cupsPrintJob}/cancel', [CupsPrinterController::class, 'cancelJob'])->name('print-manager.cancel-job');
        Route::post('print-manager/{cupsPrinter}/sync-jobs', [CupsPrinterController::class, 'syncJobs'])->name('print-manager.sync-jobs');
        Route::post('print-manager/{cupsPrinter}/send-setup', [CupsPrinterController::class, 'sendSetupEmail'])->name('print-manager.send-setup');
    });
    // ─── Identity (Entra ID / Graph API) ──────────────────────
    Route::middleware('permission:view-identity')->prefix('identity')->name('identity.')->group(function () {
        Route::get('/users', [IdentityController::class, 'users'])->name('users');
        Route::get('/users/{azureId}', [IdentityController::class, 'userDetail'])->name('user');
        Route::get('/licenses', [IdentityController::class, 'licenses'])->name('licenses');
        Route::get('/groups', [IdentityController::class, 'groups'])->name('groups');
        Route::get('/groups/{azureId}/members', [IdentityController::class, 'groupMembers'])->name('group.members');
        Route::get('/sync-logs', [IdentityController::class, 'syncLogs'])->name('sync-logs');
        Route::get('/contact-sync', [IdentityController::class, 'contactSyncIndex'])->name('contact-sync');
        Route::get('/hr-import', [OracleHrImportController::class, 'index'])->name('hr-import');
        Route::get('/hr-import/{batch}', [OracleHrImportController::class, 'show'])->name('hr-import.show');
    });
    Route::middleware('permission:manage-identity')->prefix('identity')->name('identity.')->group(function () {
        Route::post('/sync', [IdentityController::class, 'sync'])->name('sync');
        Route::patch('/users/{azureId}/toggle', [IdentityController::class, 'toggleUser'])->name('user.toggle');
        Route::patch('/users/{azureId}/reset-password', [IdentityController::class, 'resetPassword'])->name('user.reset-password');
        Route::patch('/users/{azureId}/profile', [IdentityController::class, 'updateProfile'])->name('user.update-profile');
        Route::post('/users/{azureId}/assign-license', [IdentityController::class, 'assignLicense'])->name('user.assign-license');
        Route::delete('/users/{azureId}/remove-license', [IdentityController::class, 'removeLicense'])->name('user.remove-license');
        Route::post('/users/{azureId}/add-group', [IdentityController::class, 'addGroup'])->name('user.add-group');
        Route::delete('/users/{azureId}/remove-group', [IdentityController::class, 'removeGroup'])->name('user.remove-group');
        Route::delete('/users/{azureId}/delete', [IdentityController::class, 'destroyUser'])->name('user.destroy');
        Route::post('/contact-sync/apply', [IdentityController::class, 'contactSyncApply'])->name('contact-sync.apply');
        Route::post('/contact-sync/send-reminders', [IdentityController::class, 'contactSyncSendMobileReminders'])->name('contact-sync.send-reminders');
        Route::post('/hr-import', [OracleHrImportController::class, 'upload'])->name('hr-import.upload');
        Route::post('/hr-import/{batch}/apply', [OracleHrImportController::class, 'apply'])->name('hr-import.apply');
        Route::post('/hr-import/rows/{row}/resolve', [OracleHrImportController::class, 'resolveRow'])->name('hr-import.resolve-row');
    });
    Route::middleware('permission:manage-identity-settings')->prefix('identity')->name('identity.')->group(function () {
        Route::post('/test-connection', [IdentityController::class, 'testConnection'])->name('test-connection');
    });

    // ─── Network (Meraki) ─────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network')->name('network.')->group(function () {
        Route::get('/', [NetworkController::class, 'overview'])->name('overview');
        Route::get('/switches', [NetworkController::class, 'switches'])->name('switches');
        Route::get('/switches/{serial}', [NetworkController::class, 'switchDetail'])->name('switch-detail');
        Route::get('/clients', [NetworkController::class, 'clients'])->name('clients');
        Route::get('/sync-logs', [NetworkController::class, 'syncLogs'])->name('sync-logs');
        // MAC search for autocomplete in asset/printer forms
        Route::get('/clients/mac-search', [NetworkController::class, 'macSearch'])->name('clients.mac-search');
        // Offices AJAX (public within view-network so asset forms can populate options)
        Route::get('/offices', [NetworkController::class, 'officesByFloor'])->name('offices');
        Route::get('/floors', [NetworkController::class, 'floorsByBranch'])->name('floors');
    });

    Route::middleware('permission:view-network-events')->prefix('network')->name('network.')->group(function () {
        Route::get('/events', [NetworkController::class, 'events'])->name('events');
    });

    Route::middleware('permission:manage-network-settings')->prefix('network')->name('network.')->group(function () {
        // GET sync redirect (prevents MethodNotAllowed when someone navigates directly via URL)
        Route::get('/sync', fn () => redirect()->route('admin.network.overview'))->name('sync.redirect');
        Route::post('/sync', [NetworkController::class, 'sync'])->name('sync');
        Route::post('/test-connection', [NetworkController::class, 'testConnection'])->name('test-connection');

        // ── Uplink port management ───────────────────────────────
        Route::patch('/switches/{serial}/uplink-ports', [NetworkController::class, 'setUplinkPorts'])->name('switches.uplink-ports');

        // ── Legacy location management (kept for backward compat) ──
        Route::post('/floors', [NetworkController::class, 'storeFloor'])->name('floors.store');
        Route::put('/floors/{floor}', [NetworkController::class, 'updateFloor'])->name('floors.update');
        Route::delete('/floors/{floor}', [NetworkController::class, 'destroyFloor'])->name('floors.destroy');

        Route::post('/racks', [NetworkController::class, 'storeRack'])->name('racks.store');
        Route::put('/racks/{rack}', [NetworkController::class, 'updateRack'])->name('racks.update');
        Route::delete('/racks/{rack}', [NetworkController::class, 'destroyRack'])->name('racks.destroy');

        Route::post('/switches/{serial}/assign-location', [NetworkController::class, 'assignLocation'])->name('switches.assign-location');

        // ── Quick-edit a switch from the unified switches table.
        //    Canonical write goes to devices; Meraki + SNMP rows are
        //    synced where they exist. ────────────────────────────────
        Route::put('/switches/{device}/update',
            [NetworkController::class, 'updateSwitch'])
            ->name('switches.update');

        // ── One-click "Add to SNMP" for a switch-class device ─────────
        Route::post('/switches/{device}/add-to-snmp',
            [NetworkController::class, 'addToSnmp'])
            ->name('switches.add-to-snmp');

        // ── Bulk: stub-create SNMP hosts for every switch-class device ─
        Route::post('/switches/bulk-add-to-snmp',
            [NetworkController::class, 'bulkAddToSnmp'])
            ->name('switches.bulk-add-to-snmp');

        // ── Bulk: extract SNMP creds from saved running-configs
        //         and upsert them on the matching MonitoredHost. ─────
        Route::post('/switches/sync-snmp-from-configs',
            [NetworkController::class, 'syncSnmpFromConfigs'])
            ->name('switches.sync-snmp-from-configs');
    });

    // ─── VPN Hub ──────────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/vpn')->name('network.vpn.')->group(function () {
        Route::get('/', [VpnHubController::class, 'index'])->name('index');
        Route::get('/create', [VpnHubController::class, 'create'])->name('create');
        Route::post('/', [VpnHubController::class, 'store'])->name('store');
        Route::get('/{tunnel}/edit', [VpnHubController::class, 'edit'])->name('edit');
        Route::put('/{tunnel}', [VpnHubController::class, 'update'])->name('update');
        Route::delete('/{tunnel}', [VpnHubController::class, 'destroy'])->name('destroy');
        Route::post('/{tunnel}/up', [VpnHubController::class, 'initiate'])->name('up');
        Route::post('/{tunnel}/down', [VpnHubController::class, 'terminate'])->name('down');
        Route::post('/{tunnel}/child/{action}', [VpnHubController::class, 'childAction'])
            ->whereIn('action', ['up', 'down'])->name('child');
        Route::post('/reload', [VpnHubController::class, 'reload'])->name('reload');
        Route::get('/logs', [VpnHubController::class, 'showLogs'])->name('logs');
        Route::get('/{tunnel}/status', [VpnHubController::class, 'checkStatus'])->name('status');
    });

    // ─── Branch Tunnel Health ─────────────────────────────────
    // Self-contained per-branch firewall ping board. Not tied to the VPN Hub —
    // tunnels are created on the Azure VPN gateway now. Viewing needs
    // view-network; adding/editing/removing branches needs manage-network-settings.
    Route::middleware('auth')->prefix('network/tunnel-health')->name('network.tunnel-health.')->group(function () {
        Route::get('/', [TunnelHealthController::class, 'index'])->middleware('permission:view-network')->name('index');
        Route::get('/data', [TunnelHealthController::class, 'data'])->middleware('permission:view-network')->name('data');
        Route::post('/ping', [TunnelHealthController::class, 'pingNow'])->middleware('permission:view-network')->name('ping');
        Route::post('/', [TunnelHealthController::class, 'store'])->middleware('permission:manage-network-settings')->name('store');
        Route::put('/{tunnel}', [TunnelHealthController::class, 'update'])->middleware('permission:manage-network-settings')->name('update');
        Route::delete('/{tunnel}', [TunnelHealthController::class, 'destroy'])->middleware('permission:manage-network-settings')->name('destroy');
    });

    // ─── Diagnostics ──────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/diagnostics')->name('network.diagnostics.')->group(function () {
        Route::get('/', [DiagnosticsController::class, 'index'])->name('index');
        Route::post('/ping', [DiagnosticsController::class, 'ping'])->name('ping');
        Route::post('/tcp-check', [DiagnosticsController::class, 'tcpCheck'])->name('tcp-check');
    });

    // ─── SNMP Monitoring ──────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/monitoring')->name('network.monitoring.')->group(function () {
        Route::get('/', [SnmpMonitoringController::class, 'index'])->name('index');
        Route::get('/hosts-list', [SnmpMonitoringController::class, 'hostsList'])->name('hosts.list');
        Route::get('/dashboard', [SnmpMonitoringController::class, 'monitoringDashboard'])->name('dashboard');
        Route::get('/hosts/{host}', [SnmpMonitoringController::class, 'show'])->name('show');
        Route::get('/hosts/{host}/settings', [SnmpMonitoringController::class, 'settings'])->name('hosts.settings');
        Route::post('/hosts/{host}/discover-device', [SnmpMonitoringController::class, 'discoverDevice'])->name('hosts.discover-device');
        Route::post('/hosts/{host}/discover-interfaces', [SnmpMonitoringController::class, 'discoverInterfaces'])->name('hosts.discover-interfaces');
        Route::post('/hosts/{host}/sensors', [SnmpMonitoringController::class, 'storeSensor'])->name('hosts.sensors.store');
        Route::delete('/hosts/{host}/sensors/{sensor}', [SnmpMonitoringController::class, 'destroySensor'])->name('hosts.sensors.destroy');
        Route::post('/hosts', [SnmpMonitoringController::class, 'storeHost'])->name('hosts.store');
        Route::put('/hosts/{host}', [SnmpMonitoringController::class, 'updateHost'])->name('hosts.update');
        Route::post('/hosts/{host}/ping', [SnmpMonitoringController::class, 'pingHost'])->name('hosts.ping');
        Route::delete('/hosts/{host}', [SnmpMonitoringController::class, 'destroyHost'])->name('hosts.destroy');
        Route::get('/mibs', [SnmpMonitoringController::class, 'mibs'])->name('mibs');
        Route::post('/mibs', [SnmpMonitoringController::class, 'storeMib'])->name('mibs.store');
        Route::get('/mibs/{mib}', [SnmpMonitoringController::class, 'viewMib'])->name('mibs.view');
        Route::post('/hosts/{host}/mib-assign', [SnmpMonitoringController::class, 'updateMibAssignment'])->name('hosts.mib-assign');
        Route::post('/hosts/{host}/force-poll', [SnmpMonitoringController::class, 'forcePoll'])->name('hosts.force-poll');
        Route::post('/hosts/{host}/mib-sensors', [SnmpMonitoringController::class, 'storeMibSensors'])->name('hosts.mib-sensors.store');
        Route::get('/hosts/{host}/metrics', [SnmpMonitoringController::class, 'metrics'])->name('hosts.metrics');
        Route::get('/health', [SnmpMonitoringController::class, 'snmpHealth'])->name('health');
        Route::post('/poll-all', [SnmpMonitoringController::class, 'pollAll'])->name('poll-all');
        Route::post('/poll-all-sync', [SnmpMonitoringController::class, 'pollAllSync'])->name('poll-all-sync');
        // ── Device Metrics (ApexCharts AJAX) ──────────────────────────────
        Route::get('/hosts/{host}/metrics/traffic', [DeviceMetricsController::class, 'getTrafficData'])->name('hosts.metrics.traffic');
        Route::get('/hosts/{host}/metrics/cpu', [DeviceMetricsController::class, 'getCpuData'])->name('hosts.metrics.cpu');
        Route::get('/hosts/{host}/metrics/memory', [DeviceMetricsController::class, 'getMemoryData'])->name('hosts.metrics.memory');
        Route::get('/hosts/{host}/metrics/interfaces', [DeviceMetricsController::class, 'getInterfaceData'])->name('hosts.metrics.interfaces');
    });

    // ─── Sensor Chart & History (Phase 7) ──────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->group(function () {
        Route::get('sensors/{sensor}/history', [SnmpMonitoringController::class, 'sensorHistory'])
            ->name('sensors.history');
        Route::get('sensors/{sensor}/chart', function (\App\Models\SnmpSensor $sensor) {
            return view('admin.sensors.chart', compact('sensor'));
        })->name('sensors.chart');
    });

    // ─── Printer Toner History API (Phase 7) ───────────────────────
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('printers/{printer}/toner-history', [PrinterController::class, 'tonerHistory'])
            ->name('printers.toner-history');
    });

    // ─── Workers Dashboard ─────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/workers')->name('network.workers.')->group(function () {
        Route::get('/', [WorkersDashboardController::class, 'index'])->name('index');
        Route::post('/run-ping-all', [WorkersDashboardController::class, 'runPingAll'])->name('run-ping');
        Route::post('/run-snmp-all', [WorkersDashboardController::class, 'runSnmpAll'])->name('run-snmp');
        Route::post('/discover-host/{host}', [WorkersDashboardController::class, 'runDiscoverHost'])->name('discover-host');
        Route::post('/discover-interfaces/{host}', [WorkersDashboardController::class, 'runDiscoverInterfaces'])->name('discover-interfaces');
        Route::post('/clear-failed', [WorkersDashboardController::class, 'clearFailedJobs'])->name('clear-failed');
    });

    // ─── IP Scanner ───────────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/scanner')->name('network.scanner.')->group(function () {
        Route::get('/', [IpScannerController::class, 'index'])->name('index');
        Route::post('/scan', [IpScannerController::class, 'scan'])->name('scan');
    });

    // ─── ISP Connections ────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/isp')->name('network.isp.')->group(function () {
        Route::get('/', [IspConnectionController::class, 'index'])->name('index');
        Route::get('/create', [IspConnectionController::class, 'create'])->name('create');
        Route::get('/{isp}/edit', [IspConnectionController::class, 'edit'])->name('edit');
    });
    Route::middleware('permission:manage-network-settings')->prefix('network/isp')->name('network.isp.')->group(function () {
        Route::post('/', [IspConnectionController::class, 'store'])->name('store');
        Route::put('/{isp}', [IspConnectionController::class, 'update'])->name('update');
        Route::delete('/{isp}', [IspConnectionController::class, 'destroy'])->name('destroy');
    });

    // ─── ISP Report (renewal/cost dashboard) ────────────────────
    Route::middleware('permission:view-network')->prefix('network/isp-report')->name('network.isp-report.')->group(function () {
        Route::get('/', [IspReportController::class, 'index'])->name('index');
        Route::get('/export', [IspReportController::class, 'export'])->name('export');
    });

    // ─── ISP Providers (catalog + packages) ─────────────────────
    Route::middleware('permission:view-network')->prefix('network/isp-providers')->name('network.isp-providers.')->group(function () {
        Route::get('/', [IspProviderController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-network-settings')->prefix('network/isp-providers')->name('network.isp-providers.')->group(function () {
        Route::post('/', [IspProviderController::class, 'store'])->name('store');
        Route::put('/{ispProvider}', [IspProviderController::class, 'update'])->name('update');
        Route::delete('/{ispProvider}', [IspProviderController::class, 'destroy'])->name('destroy');
        Route::post('/{ispProvider}/packages', [IspProviderController::class, 'storePackage'])->name('packages.store');
        Route::put('/{ispProvider}/packages/{package}', [IspProviderController::class, 'updatePackage'])->name('packages.update');
        Route::delete('/{ispProvider}/packages/{package}', [IspProviderController::class, 'destroyPackage'])->name('packages.destroy');
    });

    // ─── IP Reservations (IPAM) ─────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/ip-reservations')->name('network.ip-reservations.')->group(function () {
        Route::get('/', [IpReservationController::class, 'index'])->name('index');
        Route::get('/create', [IpReservationController::class, 'create'])->name('create');
        Route::get('/{reservation}/edit', [IpReservationController::class, 'edit'])->name('edit');
        Route::get('/ajax/get-available-ip', [IpReservationController::class, 'getAvailableIp'])->name('get-available-ip');
    });
    Route::middleware('permission:manage-network-settings')->prefix('network/ip-reservations')->name('network.ip-reservations.')->group(function () {
        Route::post('/', [IpReservationController::class, 'store'])->name('store');
        Route::put('/{reservation}', [IpReservationController::class, 'update'])->name('update');
        Route::delete('/{reservation}', [IpReservationController::class, 'destroy'])->name('destroy');
    });

    // ─── Landlines ──────────────────────────────────────────────
    Route::middleware('permission:view-extensions')->prefix('telecom/landlines')->name('telecom.landlines.')->group(function () {
        Route::get('/', [LandlineController::class, 'index'])->name('index');
        Route::get('/create', [LandlineController::class, 'create'])->name('create');
        Route::get('/{landline}/edit', [LandlineController::class, 'edit'])->name('edit');
    });
    Route::middleware('permission:manage-extensions')->prefix('telecom/landlines')->name('telecom.landlines.')->group(function () {
        Route::post('/', [LandlineController::class, 'store'])->name('store');
        Route::put('/{landline}', [LandlineController::class, 'update'])->name('update');
        Route::delete('/{landline}', [LandlineController::class, 'destroy'])->name('destroy');
    });

    // ─── SLA Dashboard ─────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/sla')->name('network.sla.')->group(function () {
        Route::get('/', [SlaController::class, 'index'])->name('index');
        Route::get('/{isp}', [SlaController::class, 'detail'])->name('detail');
    });

    // ─── Port Map ──────────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/port-map')->name('network.port-map.')->group(function () {
        Route::get('/', [PortMapController::class, 'index'])->name('index');
    });

    // ─── Network Topology ──────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/topology')->name('network.topology.')->group(function () {
        Route::get('/', [TopologyController::class, 'index'])->name('index');
        Route::get('/data', [TopologyController::class, 'data'])->name('data');
    });

    // ─── DHCP Leases ────────────────────────────────────────────
    Route::middleware('permission:view-dhcp-leases')->prefix('network/dhcp')->name('network.dhcp.')->group(function () {
        Route::get('/', [DhcpLeaseController::class, 'index'])->name('index');
        Route::get('/widget', [DhcpLeaseController::class, 'widget'])->name('widget');
        Route::get('/device-search', [DhcpLeaseController::class, 'deviceSearch'])->name('device-search');
        Route::post('/{lease}/link-asset', [DhcpLeaseController::class, 'linkAsset'])->name('link-asset');
        Route::get('/{lease}', [DhcpLeaseController::class, 'show'])->name('show');
    });

    // ─── IPAM Subnets ───────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/ipam')->name('network.ipam.')->group(function () {
        Route::get('/', [IpamController::class, 'index'])->name('index');
        Route::get('/search', [IpamController::class, 'search'])->name('search');
        Route::post('/', [IpamController::class, 'store'])->name('store')->middleware('permission:manage-network-settings');
        Route::get('/{subnet}', [IpamController::class, 'show'])->name('show');
        Route::get('/{subnet}/edit', [IpamController::class, 'edit'])->name('edit')->middleware('permission:manage-network-settings');
        Route::put('/{subnet}', [IpamController::class, 'update'])->name('update')->middleware('permission:manage-network-settings');
        Route::delete('/{subnet}', [IpamController::class, 'destroy'])->name('destroy')->middleware('permission:manage-network-settings');
    });

    // ─── Sophos Firewalls ───────────────────────────────────────
    Route::prefix('network/sophos')->name('network.sophos.')->group(function () {
        Route::get('/', [SophosFirewallController::class, 'index'])->name('index')->middleware('permission:view-sophos');
        Route::get('/create', [SophosFirewallController::class, 'create'])->name('create')->middleware('permission:manage-sophos');
        Route::post('/', [SophosFirewallController::class, 'store'])->name('store')->middleware('permission:manage-sophos');
        Route::get('/{firewall}', [SophosFirewallController::class, 'show'])->name('show')->middleware('permission:view-sophos');
        Route::get('/{firewall}/edit', [SophosFirewallController::class, 'edit'])->name('edit')->middleware('permission:manage-sophos');
        Route::put('/{firewall}', [SophosFirewallController::class, 'update'])->name('update')->middleware('permission:manage-sophos');
        Route::delete('/{firewall}', [SophosFirewallController::class, 'destroy'])->name('destroy')->middleware('permission:manage-sophos');
        Route::post('/{firewall}/sync', [SophosFirewallController::class, 'sync'])->name('sync')->middleware('permission:manage-sophos');
        Route::post('/{firewall}/test', [SophosFirewallController::class, 'testConnection'])->name('test')->middleware('permission:manage-sophos');
    });

    // ─── Sophos Central (cloud — APs, firewall fleet, alerts) ───
    Route::prefix('network/sophos-central')->name('network.sophos-central.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SophosCentralController::class, 'index'])->name('index')->middleware('permission:view-sophos');
        Route::post('/sync', [\App\Http\Controllers\Admin\SophosCentralController::class, 'sync'])->name('sync')->middleware('permission:manage-sophos');
    });

    // ─── Access Points (multi-vendor: Sophos, TP-Link/Omada) ───
    Route::prefix('network/access-points')->name('network.access-points.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AccessPointController::class, 'index'])->name('index')->middleware('permission:view-access-points');
        Route::post('/import', [\App\Http\Controllers\Admin\AccessPointController::class, 'import'])->name('import')->middleware('permission:manage-access-points');
        Route::post('/ping-all', [\App\Http\Controllers\Admin\AccessPointController::class, 'pingAll'])->name('ping-all')->middleware('permission:manage-access-points');
        Route::post('/{accessPoint}/ping', [\App\Http\Controllers\Admin\AccessPointController::class, 'pingNow'])->name('ping')->middleware('permission:manage-access-points');
        Route::post('/{accessPoint}/toggle', [\App\Http\Controllers\Admin\AccessPointController::class, 'toggleMonitor'])->name('toggle')->middleware('permission:manage-access-points');
        Route::put('/{accessPoint}', [\App\Http\Controllers\Admin\AccessPointController::class, 'update'])->name('update')->middleware('permission:manage-access-points');
        Route::delete('/{accessPoint}', [\App\Http\Controllers\Admin\AccessPointController::class, 'destroy'])->name('destroy')->middleware('permission:manage-access-points');
    });

    // ─── DNS Management ──────────────────────────────────────
    Route::prefix('network/dns')->name('network.dns.')->group(function () {
        // Lookup (before {account} wildcard)
        Route::get('/lookup', [DnsLookupController::class, 'index'])->name('lookup.index')->middleware('permission:view-dns');
        Route::post('/lookup', [DnsLookupController::class, 'check'])->name('lookup.check')->middleware('permission:view-dns');

        // Accounts CRUD
        Route::get('/', [DnsAccountController::class, 'index'])->name('index')->middleware('permission:view-dns');
        Route::get('/create', [DnsAccountController::class, 'create'])->name('create')->middleware('permission:manage-dns');
        Route::post('/', [DnsAccountController::class, 'store'])->name('store')->middleware('permission:manage-dns');
        Route::get('/{account}/edit', [DnsAccountController::class, 'edit'])->name('edit')->middleware('permission:manage-dns');
        Route::put('/{account}', [DnsAccountController::class, 'update'])->name('update')->middleware('permission:manage-dns');
        Route::delete('/{account}', [DnsAccountController::class, 'destroy'])->name('destroy')->middleware('permission:manage-dns');
        Route::post('/{account}/test', [DnsAccountController::class, 'testConnection'])->name('test')->middleware('permission:manage-dns');

        // Domains
        Route::get('/{account}/domains', [DnsDomainsController::class, 'index'])->name('domains.index')->middleware('permission:view-dns');
        Route::get('/{account}/domains/{domain}', [DnsDomainsController::class, 'show'])->name('domains.show')->middleware('permission:view-dns');
        Route::patch('/{account}/domains/{domain}', [DnsDomainsController::class, 'update'])->name('domains.update')->middleware('permission:manage-dns');

        // DNS Records
        Route::get('/{account}/domains/{domain}/records', [DnsRecordsController::class, 'index'])->name('records.index')->middleware('permission:view-dns');
        Route::post('/{account}/domains/{domain}/records', [DnsRecordsController::class, 'store'])->name('records.store')->middleware('permission:manage-dns');
        Route::put('/{account}/domains/{domain}/records', [DnsRecordsController::class, 'update'])->name('records.update')->middleware('permission:manage-dns');
        Route::delete('/{account}/domains/{domain}/records', [DnsRecordsController::class, 'destroy'])->name('records.destroy')->middleware('permission:manage-dns');

        // Nameservers
        Route::get('/{account}/domains/{domain}/nameservers', [DnsNameserversController::class, 'show'])->name('nameservers.show')->middleware('permission:view-dns');
        Route::put('/{account}/domains/{domain}/nameservers', [DnsNameserversController::class, 'update'])->name('nameservers.update')->middleware('permission:manage-dns');

        // Subdomains
        Route::get('/{account}/domains/{domain}/subdomains', [SubdomainController::class, 'index'])->name('subdomains.index')->middleware('permission:view-dns');
        Route::post('/{account}/domains/{domain}/subdomains', [SubdomainController::class, 'store'])->name('subdomains.store')->middleware('permission:manage-dns');
        Route::delete('/{account}/domains/{domain}/subdomains/{subdomain}', [SubdomainController::class, 'destroy'])->name('subdomains.destroy')->middleware('permission:manage-dns');
        Route::post('/{account}/domains/{domain}/subdomains/sync', [SubdomainController::class, 'sync'])->name('subdomains.sync')->middleware('permission:manage-dns');

        // SSL Certificates
        Route::get('/{account}/domains/{domain}/certificates', [SslCertificateController::class, 'index'])->name('certificates.index')->middleware('permission:view-dns');
        Route::post('/{account}/domains/{domain}/certificates', [SslCertificateController::class, 'store'])->name('certificates.store')->middleware('permission:manage-dns');
        Route::get('/{account}/domains/{domain}/certificates/{cert}', [SslCertificateController::class, 'show'])->name('certificates.show')->middleware('permission:view-dns');
        Route::post('/{account}/domains/{domain}/certificates/{cert}/renew', [SslCertificateController::class, 'renew'])->name('certificates.renew')->middleware('permission:manage-dns');
        Route::post('/{account}/domains/{domain}/certificates/{cert}/revoke', [SslCertificateController::class, 'revoke'])->name('certificates.revoke')->middleware('permission:manage-dns');
        Route::delete('/{account}/domains/{domain}/certificates/{cert}', [SslCertificateController::class, 'destroy'])->name('certificates.destroy')->middleware('permission:manage-dns');
        Route::get('/{account}/domains/{domain}/certificates/{cert}/export', [SslCertificateController::class, 'export'])->name('certificates.export')->middleware('permission:manage-dns');
    });

    // ─── Notifications (all authenticated users) ──────────────
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('settings', [NotificationController::class, 'settings'])->name('settings');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::put('settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
    });

    // ─── Telnet Client ────────────────────────────────────────
    Route::middleware('permission:view-noc')->prefix('telnet')->name('telnet.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\TelnetController::class, 'index'])->name('index');
        Route::post('/connect', [\App\Http\Controllers\Admin\TelnetController::class, 'connect'])->name('connect');
        Route::get('/terminal', [\App\Http\Controllers\Admin\TelnetController::class, 'terminal'])->name('terminal');
    });

    // ─── Web Browser (custom URL proxy) — RETIRED ──────────────
    // Replaced by the Remote Browser Portal at /portal. Routes removed so any
    // bookmarked /admin/browser URLs 404. Controller file preserved in case we
    // want to restore. To re-enable, un-comment the block below.
    // Route::middleware(['permission:view-noc', 'throttle:300,1'])->prefix('browser')->name('browser.')->group(function () {
    //     Route::get('/',                [\App\Http\Controllers\Admin\WebBrowserController::class, 'index']) ->name('index');
    //     Route::match(['GET','POST'], '/fetch', [\App\Http\Controllers\Admin\WebBrowserController::class, 'fetch']) ->name('fetch');
    // });

    // ─── Email Marketing — ADMIN MANAGEMENT (settings + suppressions + quota) ────
    // Marketing employees use the portal at /portal/marketing. This admin block
    // is for sysadmins managing SES credentials and the global suppression list.
    Route::middleware('permission:manage-email-marketing')
        ->prefix('email-marketing')->name('email-marketing.')
        ->group(function () {
            Route::get('settings', [EmailMarketingSettingsController::class, 'index'])->name('settings');
            Route::post('settings', [EmailMarketingSettingsController::class, 'update'])
                ->middleware('permission:manage-email-marketing-settings');
            Route::post('settings/test-send', [EmailMarketingSettingsController::class, 'testSend'])
                ->middleware('permission:manage-email-marketing-settings')->name('settings.test-send');

            Route::get('suppressions', [EmAdminSuppressionsController::class, 'index'])->name('suppressions');
            Route::post('suppressions', [EmAdminSuppressionsController::class, 'store'])->name('suppressions.store');
            Route::delete('suppressions/{suppression}', [EmAdminSuppressionsController::class, 'destroy'])->name('suppressions.destroy');
            Route::post('suppressions/import', [EmAdminSuppressionsController::class, 'import'])->name('suppressions.import');

            Route::get('quota', [EmAdminQuotaController::class, 'index'])->name('quota');

            // Campaign approvals — super_admin only (enforced in the controller).
            Route::get('approvals', [CampaignApprovalsController::class, 'index'])->name('approvals.index');
            Route::post('approvals/{campaign}/approve', [CampaignApprovalsController::class, 'approve'])->name('approvals.approve');
            Route::post('approvals/{campaign}/reject', [CampaignApprovalsController::class, 'reject'])->name('approvals.reject');

            // Sender allowlist (super_admin / settings-manager only).
            Route::middleware('permission:manage-email-marketing-settings')->group(function () {
                Route::get('senders', [\App\Http\Controllers\Admin\EmailMarketing\SenderIdentitiesController::class, 'index'])->name('senders.index');
                Route::post('senders', [\App\Http\Controllers\Admin\EmailMarketing\SenderIdentitiesController::class, 'store'])->name('senders.store');
                Route::put('senders/{identity}', [\App\Http\Controllers\Admin\EmailMarketing\SenderIdentitiesController::class, 'update'])->name('senders.update');
                Route::delete('senders/{identity}', [\App\Http\Controllers\Admin\EmailMarketing\SenderIdentitiesController::class, 'destroy'])->name('senders.destroy');
                Route::post('senders/{identity}/default', [\App\Http\Controllers\Admin\EmailMarketing\SenderIdentitiesController::class, 'setDefault'])->name('senders.default');
            });
        });

    // ─── Remote Browser Portal — ADMIN MANAGEMENT ONLY ───────────────
    // User-facing portal is mounted separately at /portal (isolated from admin).
    Route::middleware('permission:manage-browser-portal')
        ->prefix('browser-portal')->name('browser-portal.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\BrowserPortal\AdminBrowserPortalController::class, 'index'])->name('index');
            Route::get('/events', [\App\Http\Controllers\Admin\BrowserPortal\AdminBrowserPortalController::class, 'events'])->name('events');
            Route::get('/settings', [\App\Http\Controllers\Admin\BrowserPortal\BrowserPortalSettingsController::class, 'index'])->name('settings');
            Route::post('/settings', [\App\Http\Controllers\Admin\BrowserPortal\BrowserPortalSettingsController::class, 'update'])->name('settings.update');
            Route::get('/{sessionId}/logs', [\App\Http\Controllers\Admin\BrowserPortal\AdminBrowserPortalController::class, 'logs'])->name('logs')
                ->where('sessionId', '[a-z0-9]{12}');
            Route::get('/{sessionId}/logs/stream', [\App\Http\Controllers\Admin\BrowserPortal\AdminBrowserPortalController::class, 'logStream'])->name('logs.stream')
                ->where('sessionId', '[a-z0-9]{12}');
            Route::delete('/{sessionId}', [\App\Http\Controllers\Admin\BrowserPortal\AdminBrowserPortalController::class, 'destroy'])->name('destroy')
                ->where('sessionId', '[a-z0-9]{12}');
        });

    // ─── NOC Dashboard ────────────────────────────────────────
    Route::middleware('permission:view-noc')->prefix('noc')->name('noc.')->group(function () {
        Route::get('/', [NocController::class, 'dashboard'])->name('dashboard');
        Route::get('/overview', [\App\Http\Controllers\Admin\NocOverviewController::class, 'index'])->name('overview.index');
        Route::get('/overview/chart', [\App\Http\Controllers\Admin\NocOverviewController::class, 'chart'])->name('overview.chart');
        Route::get('/branch/{id}', [NocController::class, 'branch'])->name('branch');
        Route::get('/events', [NocController::class, 'events'])->name('events');
        Route::get('/alerts', [\App\Http\Controllers\Admin\AlertFeedController::class, 'index'])->name('alerts');
        Route::get('/alerts/{id}/timeline', [\App\Http\Controllers\Admin\AlertFeedController::class, 'timeline'])->name('alerts.timeline');

        // AJAX endpoint for heavy dashboard sections (UCM, Branch Health, VPN details)
        Route::get('/dashboard-data', [NocController::class, 'dashboardHeavyData'])->name('dashboard.data');

        // Extensions page + Wallboard + Extension Grid API
        Route::get('/extensions', [NocController::class, 'extensionsPage'])->name('extensions');
        Route::get('/wallboard', [NocController::class, 'wallboard'])->name('wallboard');
        Route::get('/wallboard-data', [NocController::class, 'wallboardData'])->name('wallboard.data');
        Route::get('/extension-grid', [NocController::class, 'extensionGrid'])->name('extension-grid');
    });
    Route::middleware('permission:manage-noc')->prefix('noc')->name('noc.')->group(function () {
        Route::post('/events/{id}/acknowledge', [NocController::class, 'acknowledge'])->name('events.acknowledge');
        Route::post('/events/{id}/resolve', [NocController::class, 'resolve'])->name('events.resolve');
        Route::post('/events/{id}/resend', [NocController::class, 'resend'])->name('events.resend');
    });

    // ─── Incidents ──────────────────────────────────────────────
    Route::middleware('permission:view-incidents')->prefix('noc/incidents')->name('noc.incidents.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\IncidentController::class, 'index'])->name('index');
        Route::get('/{incident}', [\App\Http\Controllers\Admin\IncidentController::class, 'show'])->name('show');
    });
    Route::middleware('permission:manage-incidents')->prefix('noc/incidents')->name('noc.incidents.')->group(function () {
        Route::get('/create', [\App\Http\Controllers\Admin\IncidentController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\IncidentController::class, 'store'])->name('store');
        Route::get('/{incident}/edit', [\App\Http\Controllers\Admin\IncidentController::class, 'edit'])->name('edit');
        Route::put('/{incident}', [\App\Http\Controllers\Admin\IncidentController::class, 'update'])->name('update');
        Route::post('/{incident}/comment', [\App\Http\Controllers\Admin\IncidentController::class, 'addComment'])->name('comment');
        Route::get('/from-event/{eventId}', [\App\Http\Controllers\Admin\IncidentController::class, 'createFromEvent'])->name('from-event');
    });

    // ─── Alert Rule Engine ───────────────────────────────────
    Route::middleware('permission:manage-noc')->group(function () {
        Route::resource('alert-rules', AlertRuleController::class);
        Route::get('alert-rules/{alertRule}/states', [AlertRuleController::class, 'states'])->name('alert-rules.states');
        Route::post('alert-states/{alertState}/acknowledge', [AlertRuleController::class, 'acknowledge'])->name('alert-states.acknowledge');
        Route::get('alerts/dashboard', [AlertRuleController::class, 'alerts'])->name('alerts.dashboard');
    });

    // ─── Syslog (rsyslog → MySQL → UI) ───────────────────────
    Route::middleware('permission:view-syslog')->prefix('syslog')->name('syslog.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SyslogController::class, 'index'])->name('index');
        Route::get('/tail', [\App\Http\Controllers\Admin\SyslogController::class, 'tail'])->name('tail');
        Route::get('/sophos', [\App\Http\Controllers\Admin\SyslogController::class, 'sophos'])->name('sophos');
        Route::get('/ucm', [\App\Http\Controllers\Admin\SyslogController::class, 'ucm'])->name('ucm');
        Route::get('/{id}', [\App\Http\Controllers\Admin\SyslogController::class, 'show'])->name('show')->whereNumber('id');
    });
    // ─── Branch Logs (per-branch VM, queried over IPsec) ─────
    Route::middleware('permission:view-syslog')->prefix('logs/branches')->name('logs.branches.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\BranchLogController::class, 'index'])->name('index');
        Route::get('/sophos', [\App\Http\Controllers\Admin\BranchLogController::class, 'sophos'])->name('sophos');
        Route::get('/ucm', [\App\Http\Controllers\Admin\BranchLogController::class, 'ucm'])->name('ucm');
        Route::get('/aggregate.json', [\App\Http\Controllers\Admin\BranchLogController::class, 'aggregate'])->name('aggregate');
    });

    // ─── Branch Log Collector management (CRUD) ──────────────
    // Manages the list of branch VMs the NOC queries: host, port, bearer token.
    Route::middleware('permission:manage-syslog')
        ->prefix('branches/log-collectors')
        ->name('branches.log-collectors.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'store'])->name('store');
            Route::post('/generate-token', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'generateToken'])->name('generate-token');
            Route::get('/{logCollector}/edit', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'edit'])->name('edit');
            Route::put('/{logCollector}', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'update'])->name('update');
            Route::delete('/{logCollector}', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'destroy'])->name('destroy');
            Route::post('/{logCollector}/test', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'test'])->name('test');
            Route::post('/refresh-all', [\App\Http\Controllers\Admin\BranchLogCollectorController::class, 'refreshAll'])->name('refresh-all');
        });

    // ─── Branch Agents (sg-branch-agent VMs: enroll, heartbeat, DDNS) ──
    // Identity/enrollment/health for the consolidated branch agent. View is
    // gated by view-branch-agents; mutations by manage-branch-agents.
    Route::prefix('branch-agents')
        ->name('branch-agents.')
        ->group(function () {
            Route::middleware('permission:view-branch-agents')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\BranchAgentController::class, 'index'])->name('index');
                Route::get('/{branchAgent}', [\App\Http\Controllers\Admin\BranchAgentController::class, 'show'])
                    ->whereNumber('branchAgent')->name('show');
            });
            Route::middleware('permission:manage-branch-agents')->group(function () {
                Route::get('/create', [\App\Http\Controllers\Admin\BranchAgentController::class, 'create'])->name('create');
                Route::post('/', [\App\Http\Controllers\Admin\BranchAgentController::class, 'store'])->name('store');
                Route::get('/{branchAgent}/edit', [\App\Http\Controllers\Admin\BranchAgentController::class, 'edit'])->name('edit');
                Route::put('/{branchAgent}', [\App\Http\Controllers\Admin\BranchAgentController::class, 'update'])->name('update');
                Route::delete('/{branchAgent}', [\App\Http\Controllers\Admin\BranchAgentController::class, 'destroy'])->name('destroy');
                Route::post('/{branchAgent}/regenerate-code', [\App\Http\Controllers\Admin\BranchAgentController::class, 'regenerateCode'])->name('regenerate-code');
                Route::post('/{branchAgent}/revoke-token', [\App\Http\Controllers\Admin\BranchAgentController::class, 'revokeToken'])->name('revoke-token');
            });
        });

    // ─── SNMP Devices (per-branch, polled by Telegraf) ──────────
    Route::middleware('permission:manage-syslog')
        ->prefix('snmp-devices')
        ->name('snmp-devices.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'index'])->name('index');
            Route::get('/discovered', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'discovered'])->name('discovered');
            Route::post('/discovered/{discovery}/approve', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'approveDiscovered'])->name('approve-discovered');
            Route::post('/discovered/{discovery}/reject', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'rejectDiscovered'])->name('reject-discovered');
            Route::get('/create', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'store'])->name('store');
            Route::get('/{snmpDevice}/edit', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'edit'])->name('edit');
            Route::put('/{snmpDevice}', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'update'])->name('update');
            Route::delete('/{snmpDevice}', [\App\Http\Controllers\Admin\SnmpDeviceController::class, 'destroy'])->name('destroy');
        });

    Route::middleware('permission:manage-syslog')->prefix('syslog')->name('syslog.')->group(function () {
        Route::get('/rules', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesIndex'])->name('rules.index');
        Route::get('/rules/create', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesCreate'])->name('rules.create');
        Route::post('/rules', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesStore'])->name('rules.store');
        Route::get('/rules/{rule}/edit', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesEdit'])->name('rules.edit');
        Route::put('/rules/{rule}', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesUpdate'])->name('rules.update');
        Route::delete('/rules/{rule}', [\App\Http\Controllers\Admin\SyslogController::class, 'rulesDestroy'])->name('rules.destroy');
        Route::post('/run-processors', [\App\Http\Controllers\Admin\SyslogController::class, 'runProcessors'])->name('run-processors');
        Route::post('/clear', [\App\Http\Controllers\Admin\SyslogController::class, 'clearAll'])->name('clear');
    });

    // ─── Workflows ────────────────────────────────────────────
    Route::middleware('permission:view-workflows')->group(function () {
        Route::get('workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('workflows/my-requests', [WorkflowController::class, 'myRequests'])->name('workflows.my-requests');
    });
    Route::middleware('permission:manage-workflows')->group(function () {
        Route::get('workflows/create', [WorkflowController::class, 'create'])->name('workflows.create');
        Route::get('workflows/preview-user', [WorkflowController::class, 'previewUser'])->name('workflows.preview-user');
        Route::post('workflows', [WorkflowController::class, 'store'])->name('workflows.store');
        Route::post('workflows/{workflow}/cancel', [WorkflowController::class, 'cancel'])->name('workflows.cancel');
    });
    Route::middleware('permission:approve-workflows')->group(function () {
        Route::get('workflows/pending', [WorkflowController::class, 'pending'])->name('workflows.pending');
        Route::post('workflows/{workflow}/approve', [WorkflowController::class, 'approve'])->name('workflows.approve');
        Route::post('workflows/{workflow}/reject', [WorkflowController::class, 'reject'])->name('workflows.reject');
        Route::post('workflows/{workflow}/retry', [WorkflowController::class, 'retry'])->name('workflows.retry');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
        Route::patch('workflows/tasks/{task}/complete', [WorkflowController::class, 'completeTask'])->name('workflows.tasks.complete');
        Route::post('workflows/{workflow}/resend-manager-form', [WorkflowController::class, 'resendManagerForm'])->name('workflows.resend-manager-form');
        Route::post('workflows/{workflow}/assign-device', [WorkflowController::class, 'assignDevice'])->name('workflows.assign-device');
        Route::post('workflows/{workflow}/return-device/{assignment}', [WorkflowController::class, 'returnDevice'])->name('workflows.return-device');
    });
    Route::middleware('permission:view-workflows')->group(function () {
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->name('workflows.show');
    });

    // ─── AvePoint Module ──────────────────────────────────────
    Route::middleware('permission:view-avepoint')->group(function () {
        Route::get('avepoint', [\App\Http\Controllers\Admin\AvePointController::class, 'dashboard'])->name('avepoint.dashboard');
        Route::get('avepoint/users', [\App\Http\Controllers\Admin\AvePointController::class, 'users'])->name('avepoint.users');
        Route::get('avepoint/jobs', [\App\Http\Controllers\Admin\AvePointController::class, 'jobs'])->name('avepoint.jobs');
        Route::get('avepoint/backups', [\App\Http\Controllers\Admin\AvePointController::class, 'backups'])->name('avepoint.backups');
        Route::get('avepoint/backups/{backup}', [\App\Http\Controllers\Admin\AvePointController::class, 'showBackup'])->name('avepoint.backup.show');
    });
    Route::middleware('permission:manage-avepoint')->group(function () {
        Route::post('avepoint/request', [\App\Http\Controllers\Admin\AvePointController::class, 'requestBackup'])->name('avepoint.request');
        Route::post('avepoint/backups/{backup}/retry', [\App\Http\Controllers\Admin\AvePointController::class, 'retry'])->name('avepoint.backup.retry');
        Route::post('avepoint/backups/{backup}/upload', [\App\Http\Controllers\Admin\AvepointBackupUploadController::class, 'upload'])->name('avepoint.backup.upload');
    });

    // ─── Offboarding ──────────────────────────────────────────
    Route::middleware('permission:view-offboarding')->group(function () {
        Route::get('offboarding', [\App\Http\Controllers\Admin\OffboardingController::class, 'index'])->name('offboarding.index');
        Route::get('offboarding/{offboardingWorkflow}', [\App\Http\Controllers\Admin\OffboardingController::class, 'show'])->name('offboarding.show');
    });
    Route::middleware('permission:manage-offboarding')->group(function () {
        Route::post('offboarding/{offboardingWorkflow}/resend', [\App\Http\Controllers\Admin\OffboardingController::class, 'resendManagerEmail'])->name('offboarding.resend');
        Route::post('offboarding/{offboardingWorkflow}/cancel', [\App\Http\Controllers\Admin\OffboardingController::class, 'cancel'])->name('offboarding.cancel');
        Route::post('offboarding/{offboardingWorkflow}/force-delete', [\App\Http\Controllers\Admin\OffboardingController::class, 'forceDelete'])->name('offboarding.force-delete');
        Route::post('offboarding/backup/{backup}/upload', [\App\Http\Controllers\Admin\OffboardingBackupUploadController::class, 'upload'])->name('offboarding.backup.upload');
    });

    // ─── Employees ────────────────────────────────────────────
    Route::middleware('permission:view-employees')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/search', [EmployeeController::class, 'search'])->name('employees.search');
    });
    Route::middleware('permission:manage-employees')->group(function () {
        // Static routes MUST come before {employee} wildcard
        Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::get('employees/sync', [EmployeeController::class, 'showSync'])->name('employees.sync');
        Route::post('employees/sync', [EmployeeController::class, 'doSync'])->name('employees.sync.do');
        Route::post('employees/auto-link-contacts', [EmployeeController::class, 'autoLinkContacts'])->name('employees.auto-link-contacts');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    });
    Route::middleware('permission:view-employees')->group(function () {
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('employees/{employee}/report', [EmployeeController::class, 'report'])->name('employees.report');
        Route::get('employees/{employee}/card-data', [\App\Http\Controllers\EmployeeCardController::class, 'adminShareData'])->name('employees.card-data');
        Route::post('employees/{employee}/card-token/regenerate', [\App\Http\Controllers\EmployeeCardController::class, 'regenerateToken'])->name('employees.card-token.regenerate');
    });
    Route::middleware('permission:manage-employees')->group(function () {
        Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::post('employees/{employee}/link-contact', [EmployeeController::class, 'linkContact'])->name('employees.link-contact');
        Route::delete('employees/{employee}/unlink-contact', [EmployeeController::class, 'unlinkContact'])->name('employees.unlink-contact');
        Route::post('employees/{employee}/assets', [EmployeeController::class, 'assignAsset'])->name('employees.assets.assign');
        Route::patch('employees/{employee}/assets/{asset}/return', [EmployeeController::class, 'returnAsset'])->name('employees.assets.return');
        // Employee items (standalone equipment)
        Route::post('employees/{employee}/items', [EmployeeItemController::class, 'store'])->name('employees.items.store');
        Route::patch('employees/{employee}/items/{item}/return', [EmployeeItemController::class, 'returnItem'])->name('employees.items.return');
        Route::delete('employees/{employee}/items/{item}', [EmployeeItemController::class, 'destroy'])->name('employees.items.destroy');
    });

    // ─── Printer Maintenance (nested under printers) ──────────
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('printers/{printer}/maintenance',
            [PrinterMaintenanceController::class, 'index'])->name('printers.maintenance.index');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('printers/{printer}/maintenance',
            [PrinterMaintenanceController::class, 'store'])->name('printers.maintenance.store');
        Route::delete('printers/{printer}/maintenance/{log}',
            [PrinterMaintenanceController::class, 'destroy'])->name('printers.maintenance.destroy');
    });

    // ── Workflow Templates ────────────────────────────────────────
    Route::get('/workflow-templates', [WorkflowTemplateController::class, 'index'])->name('workflow-templates.index');
    Route::post('/workflow-templates', [WorkflowTemplateController::class, 'store'])->name('workflow-templates.store');
    Route::put('/workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'update'])->name('workflow-templates.update');
    Route::delete('/workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'destroy'])->name('workflow-templates.destroy');

    // ── Workflow Builder ──────────────────────────────────────────
    Route::get('/workflow-templates/{workflowTemplate}/builder', [WorkflowTemplateController::class, 'builder'])->name('workflow-templates.builder');
    Route::post('/workflow-templates/{workflowTemplate}/definition', [WorkflowTemplateController::class, 'saveDefinition'])->name('workflow-templates.save-definition');
    Route::get('/workflow-templates/{workflowTemplate}/versions', [WorkflowTemplateController::class, 'versions'])->name('workflow-templates.versions');
    Route::post('/workflow-templates/{workflowTemplate}/versions/{version}/restore', [WorkflowTemplateController::class, 'restoreVersion'])->name('workflow-templates.restore-version');
    Route::post('/workflow-templates/{workflowTemplate}/trigger', [WorkflowTriggerController::class, 'store'])->name('workflow-templates.trigger.set');
    Route::delete('/workflow-templates/{workflowTemplate}/trigger', [WorkflowTriggerController::class, 'destroy'])->name('workflow-templates.trigger.clear');

    // ── Email Logs ────────────────────────────────────────────────
    Route::get('/notifications/email-log', [EmailLogController::class, 'index'])->name('email-log.index');
    Route::delete('/notifications/email-log', [EmailLogController::class, 'clearAll'])->name('email-log.clear');

    // ── Notification Rules ────────────────────────────────────────
    Route::get('/notifications/rules', [NotificationRuleController::class, 'index'])->name('notification-rules.index');
    Route::post('/notifications/rules', [NotificationRuleController::class, 'store'])->name('notification-rules.store');
    Route::put('/notifications/rules/{notificationRule}', [NotificationRuleController::class, 'update'])->name('notification-rules.update');
    Route::delete('/notifications/rules/{notificationRule}', [NotificationRuleController::class, 'destroy'])->name('notification-rules.destroy');

    // ── License Monitors ──────────────────────────────────────────
    Route::get('/license-monitors', [LicenseMonitorController::class, 'index'])->name('license-monitors.index');
    Route::post('/license-monitors', [LicenseMonitorController::class, 'store'])->name('license-monitors.store');
    Route::put('/license-monitors/{licenseMonitor}', [LicenseMonitorController::class, 'update'])->name('license-monitors.update');
    Route::patch('/license-monitors/{licenseMonitor}/toggle', [LicenseMonitorController::class, 'toggleActive'])->name('license-monitors.toggle');
    Route::delete('/license-monitors/{licenseMonitor}', [LicenseMonitorController::class, 'destroy'])->name('license-monitors.destroy');

    // ── Allowed Domains ───────────────────────────────────────────
    Route::get('/settings/domains', [AllowedDomainController::class, 'index'])->name('settings.domains');
    Route::post('/settings/domains', [AllowedDomainController::class, 'store'])->name('settings.domains.store');
    Route::patch('/settings/domains/{allowedDomain}/primary', [AllowedDomainController::class, 'setPrimary'])->name('settings.domains.primary');
    Route::delete('/settings/domains/{allowedDomain}', [AllowedDomainController::class, 'destroy'])->name('settings.domains.destroy');

    Route::middleware('permission:manage-settings')->group(function () {
        // ── Provisioning Settings ─────────────────────────────────────
        Route::post('/settings/provisioning', [SettingsController::class, 'updateProvisioning'])->name('settings.provisioning');
        Route::get('/settings/provisioning-licenses', [SettingsController::class, 'provisioningLicenses'])->name('settings.provisioning-licenses');
        Route::post('/settings/provisioning-licenses', [SettingsController::class, 'setDefaultLicense'])->name('settings.provisioning-licenses.save');

        // ── Internet Access Levels ────────────────────────────────────
        Route::prefix('settings/internet-access-levels')->name('settings.internet-access-levels.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\InternetAccessLevelController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\InternetAccessLevelController::class, 'store'])->name('store');
            Route::put('/{internetAccessLevel}', [\App\Http\Controllers\Admin\InternetAccessLevelController::class, 'update'])->name('update');
            Route::delete('/{internetAccessLevel}', [\App\Http\Controllers\Admin\InternetAccessLevelController::class, 'destroy'])->name('destroy');
            Route::get('/azure-groups/search', [\App\Http\Controllers\Admin\InternetAccessLevelController::class, 'searchAzureGroups'])->name('azure-groups.search');
        });
    });

    // ─── Network Discovery ────────────────────────────────────────
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('network-discovery', [NetworkDiscoveryController::class, 'index'])->name('network-discovery.index');
        Route::get('network-discovery/{discoveryScan}', [NetworkDiscoveryController::class, 'show'])->name('network-discovery.show');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('network-discovery', [NetworkDiscoveryController::class, 'store'])->name('network-discovery.store');
        Route::post('network-discovery/{discoveryScan}/results/{result}/import', [NetworkDiscoveryController::class, 'import'])->name('network-discovery.import');
        Route::delete('network-discovery/{discoveryScan}', [NetworkDiscoveryController::class, 'destroy'])->name('network-discovery.destroy');
    });

    // ─── ITAM ─────────────────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam')->name('itam.')->group(function () {
        Route::get('/', [ItamController::class, 'dashboard'])->name('dashboard');
        Route::get('/mac-address', [\App\Http\Controllers\Admin\MacAddressController::class, 'index'])->name('mac-address');

        // Branch Stores (view)
        Route::get('stores', [\App\Http\Controllers\Admin\BranchStoreController::class, 'index'])->name('stores.index');
        Route::get('stores/universal', [\App\Http\Controllers\Admin\BranchStoreController::class, 'showUniversal'])->name('stores.universal');
        Route::get('stores/{branch}', [\App\Http\Controllers\Admin\BranchStoreController::class, 'show'])->name('stores.show');

        // Reports
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AssetReportController::class, 'index'])->name('index');
            Route::get('all-assets', [\App\Http\Controllers\Admin\AssetReportController::class, 'allAssets'])->name('all-assets');
            Route::get('by-branch', [\App\Http\Controllers\Admin\AssetReportController::class, 'byBranch'])->name('by-branch');
            Route::get('by-employee', [\App\Http\Controllers\Admin\AssetReportController::class, 'byEmployee'])->name('by-employee');
            Route::get('transfer-history', [\App\Http\Controllers\Admin\AssetReportController::class, 'transferHistory'])->name('transfers');
            Route::get('scrap-history', [\App\Http\Controllers\Admin\AssetReportController::class, 'scrapHistory'])->name('scraps');
            Route::get('costs', [\App\Http\Controllers\Admin\AssetReportController::class, 'costs'])->name('costs');
        });
    });

    // ─── Asset Transfer ───────────────────────────────────────────
    Route::middleware('permission:manage-itam')->prefix('itam/transfer')->name('itam.transfer.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AssetTransferController::class, 'index'])->name('index');
        Route::get('employee/{employee}/assets', [\App\Http\Controllers\Admin\AssetTransferController::class, 'assetsForEmployee'])->name('employee-assets');
        Route::get('branch-store/{branch}/assets', [\App\Http\Controllers\Admin\AssetTransferController::class, 'assetsForBranchStore'])->name('branch-store-assets');
        Route::get('universal-store/assets', [\App\Http\Controllers\Admin\AssetTransferController::class, 'assetsForUniversalStore'])->name('universal-store-assets');
        Route::post('/', [\App\Http\Controllers\Admin\AssetTransferController::class, 'store'])->name('store');
        Route::get('{group}/print', [\App\Http\Controllers\Admin\AssetTransferController::class, 'print'])->name('print');
    });

    // ─── Asset Scrap (request) ────────────────────────────────────
    Route::middleware('permission:request-scrap')->prefix('itam/scrap')->name('itam.scrap.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AssetScrapController::class, 'index'])->name('index');
        Route::get('create', [\App\Http\Controllers\Admin\AssetScrapController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\AssetScrapController::class, 'store'])->name('store');
        Route::get('{workflow}', [\App\Http\Controllers\Admin\AssetScrapController::class, 'show'])->name('show');
        Route::get('{workflow}/print', [\App\Http\Controllers\Admin\AssetScrapController::class, 'print'])->name('print');
    });
    // ─── Asset Scrap (approve / reject) ───────────────────────────
    Route::middleware('permission:approve-scrap')->prefix('itam/scrap')->name('itam.scrap.')->group(function () {
        Route::post('{workflow}/approve', [\App\Http\Controllers\Admin\AssetScrapController::class, 'approve'])->name('approve');
        Route::post('{workflow}/reject', [\App\Http\Controllers\Admin\AssetScrapController::class, 'reject'])->name('reject');
    });

    // ─── RADIUS (MAC Authentication / MAB) ────────────────────────
    Route::middleware('permission:manage-radius')->prefix('radius')->name('radius.')->group(function () {
        Route::redirect('/', '/admin/radius/macs');

        // MAC registry — RADIUS-focused view + manual add
        Route::get('macs', [\App\Http\Controllers\Admin\RadiusMacRegistryController::class, 'index'])->name('macs.index');
        Route::get('macs/create', [\App\Http\Controllers\Admin\RadiusMacRegistryController::class, 'create'])->name('macs.create');
        Route::post('macs', [\App\Http\Controllers\Admin\RadiusMacRegistryController::class, 'store'])->name('macs.store');
        Route::delete('macs/{mac}', [\App\Http\Controllers\Admin\RadiusMacRegistryController::class, 'destroy'])->name('macs.destroy');
        Route::post('macs/sync', [\App\Http\Controllers\Admin\RadiusMacRegistryController::class, 'sync'])->name('macs.sync');

        // NAS clients (switches / APs allowed to query us)
        Route::get('nas', [\App\Http\Controllers\Admin\RadiusNasController::class, 'index'])->name('nas.index');
        Route::get('nas/create', [\App\Http\Controllers\Admin\RadiusNasController::class, 'create'])->name('nas.create');
        Route::post('nas', [\App\Http\Controllers\Admin\RadiusNasController::class, 'store'])->name('nas.store');
        Route::get('nas/{nas}/edit', [\App\Http\Controllers\Admin\RadiusNasController::class, 'edit'])->name('nas.edit');
        Route::put('nas/{nas}', [\App\Http\Controllers\Admin\RadiusNasController::class, 'update'])->name('nas.update');
        Route::delete('nas/{nas}', [\App\Http\Controllers\Admin\RadiusNasController::class, 'destroy'])->name('nas.destroy');
        Route::post('nas/reload', [\App\Http\Controllers\Admin\RadiusNasController::class, 'reload'])->name('nas.reload');

        // VLAN policy (per-branch defaults)
        Route::get('vlan-policy', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'index'])->name('vlan.index');
        Route::get('vlan-policy/create', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'create'])->name('vlan.create');
        Route::post('vlan-policy', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'store'])->name('vlan.store');
        Route::get('vlan-policy/preview', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'preview'])->name('vlan.preview');
        Route::get('vlan-policy/{policy}/edit', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'edit'])->name('vlan.edit');
        Route::put('vlan-policy/{policy}', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'update'])->name('vlan.update');
        Route::delete('vlan-policy/{policy}', [\App\Http\Controllers\Admin\RadiusVlanPolicyController::class, 'destroy'])->name('vlan.destroy');

        // Per-MAC override (called from /admin/itam/mac-address modal)
        Route::post('mac-overrides/{deviceMac}', [\App\Http\Controllers\Admin\RadiusMacOverrideController::class, 'upsert'])->name('mac-overrides.upsert');
    });

    // ─── Suppliers ────────────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam/suppliers')->name('itam.suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::get('/{supplier}', [SupplierController::class, 'show'])->name('show');
    });
    Route::middleware('permission:manage-itam')->prefix('itam/suppliers')->name('itam.suppliers.')->group(function () {
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
    });

    // ─── Purchase Orders ──────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam/purchase-orders')->name('itam.purchase-orders.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('show');
        Route::get('/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->whereNumber('purchaseOrder')->name('print');
    });
    Route::middleware('permission:manage-itam')->prefix('itam/purchase-orders')->name('itam.purchase-orders.')->group(function () {
        Route::get('/create', [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
        Route::get('/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('purchaseOrder')->name('edit');
        Route::put('/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->name('update');
        Route::delete('/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->whereNumber('purchaseOrder')->name('destroy');
    });

    // ─── Software Licenses ────────────────────────────────────────
    Route::middleware('permission:view-licenses')->prefix('itam/licenses')->name('itam.licenses.')->group(function () {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-licenses')->prefix('itam/licenses')->name('itam.licenses.')->group(function () {
        Route::post('/', [LicenseController::class, 'store'])->name('store');
        Route::put('/{license}', [LicenseController::class, 'update'])->name('update');
        Route::delete('/{license}', [LicenseController::class, 'destroy'])->name('destroy');
        Route::post('/{license}/assign', [LicenseController::class, 'assign'])->name('assign');
        Route::delete('/{license}/unassign/{assignment}', [LicenseController::class, 'unassign'])->name('unassign');
    });

    // ─── Accessories ──────────────────────────────────────────────
    Route::middleware('permission:view-accessories')->prefix('itam/accessories')->name('itam.accessories.')->group(function () {
        Route::get('/', [AccessoryController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-accessories')->prefix('itam/accessories')->name('itam.accessories.')->group(function () {
        Route::post('/', [AccessoryController::class, 'store'])->name('store');
        Route::put('/{accessory}', [AccessoryController::class, 'update'])->name('update');
        Route::delete('/{accessory}', [AccessoryController::class, 'destroy'])->name('destroy');
        Route::post('/{accessory}/assign', [AccessoryController::class, 'assign'])->name('assign');
        Route::patch('/{accessory}/assignments/{assignment}/return', [AccessoryController::class, 'returnItem'])->name('return');
    });

    // ─── Assignable Search API (for license/accessory assign modals) ──
    Route::get('/api/search-assignables', function (\Illuminate\Http\Request $request) {
        $type = $request->input('type', 'employee');
        $query = $request->input('q', '');
        $items = [];

        if ($type === 'employee') {
            $items = \App\Models\Employee::query()
                ->when($query, fn ($q) => $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('job_title', 'like', "%{$query}%");
                }))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'email', 'job_title'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name.($e->email ? " ({$e->email})" : '').($e->job_title ? " — {$e->job_title}" : ''),
                ]);
        } else {
            $items = \App\Models\Device::query()
                ->when($query, fn ($q) => $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('ip_address', 'like', "%{$query}%")
                        ->orWhere('serial_number', 'like', "%{$query}%")
                        ->orWhere('asset_code', 'like', "%{$query}%");
                }))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'type', 'ip_address', 'asset_code'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name." ({$d->type})".($d->ip_address ? " — {$d->ip_address}" : '').($d->asset_code ? " [{$d->asset_code}]" : ''),
                ]);
        }

        return response()->json($items);
    })->name('api.search-assignables');

    // ─── Azure Device Sync ────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam/azure')->name('itam.azure.')->group(function () {
        Route::get('/mappings', [AzureSyncController::class, 'mappings'])->name('mappings');
        Route::get('/intune-overview', [AzureSyncController::class, 'intuneOverview'])->name('intune-overview');

        // These specific ID routes must come before the general /{azureDevice}
        Route::get('/{azureDevice}/create-device', [AzureSyncController::class, 'createDevice'])->name('create-device');
        Route::get('/{azureDevice}/preview-import', [AzureSyncController::class, 'previewImport'])->name('preview-import');
        Route::get('/{azureDevice}/json', [AzureSyncController::class, 'showJson'])->name('show-json');

        Route::get('/', [AzureSyncController::class, 'index'])->name('index');
        Route::get('/{azureDevice}', [AzureSyncController::class, 'show'])->name('show');
    });
    Route::middleware('permission:manage-itam')->prefix('itam/azure')->name('itam.azure.')->group(function () {
        Route::post('/sync', [AzureSyncController::class, 'sync'])->name('sync');
        Route::post('/sync-all-hw-data', [AzureSyncController::class, 'syncAllHwData'])->name('sync-all-hw-data');
        Route::patch('/{azureDevice}/approve', [AzureSyncController::class, 'approve'])->name('approve');
        Route::patch('/{azureDevice}/reject', [AzureSyncController::class, 'reject'])->name('reject');
        Route::post('/{azureDevice}/link-device', [AzureSyncController::class, 'linkDevice'])->name('link-device');
        Route::post('/{azureDevice}/import', [AzureSyncController::class, 'importToItam'])->name('import');
        Route::post('/{azureDevice}/sync-branch', [AzureSyncController::class, 'reDetectBranch'])->name('sync-branch');
        Route::post('/{azureDevice}/sync-hw-data', [AzureSyncController::class, 'syncHwData'])->name('sync-hw-data');
        Route::post('/{azureDevice}/confirm-user-link', [AzureSyncController::class, 'confirmUserLink'])->name('confirm-user-link');
        Route::post('/batch-import', [AzureSyncController::class, 'batchImport'])->name('batch-import');

        // Branch Mapping
        Route::get('/mappings', [AzureSyncController::class, 'mappings'])->name('mappings');
        Route::post('/mappings', [AzureSyncController::class, 'storeMapping'])->name('mappings.store');
        Route::delete('/mappings/{mapping}', [AzureSyncController::class, 'deleteMapping'])->name('mappings.delete');
        Route::post('/mappings/sync-all', [AzureSyncController::class, 'bulkSyncBranches'])->name('mappings.sync-all');
        Route::post('/mappings/sync-employees', [AzureSyncController::class, 'bulkSyncEmployeeBranches'])->name('mappings.sync-employees');
    });

    // ─── IT Tasks — RETIRED ───────────────────────────────────────
    // Module hidden from the UI and routes disabled. Controller, model and
    // Blade views preserved on disk. To re-enable, un-comment the block below.
    // Route::model('task', \App\Models\ItTask::class);
    // Route::prefix('tasks')->name('tasks.')->group(function () {
    //     Route::get('/',                [ItTaskController::class, 'index'])        ->name('index');
    //     Route::get('/my-tasks',        [ItTaskController::class, 'myTasks'])      ->name('my-tasks');
    //     Route::get('/kanban',          [ItTaskController::class, 'kanban'])       ->name('kanban');
    //     Route::get('/create',          [ItTaskController::class, 'create'])       ->name('create');
    //     Route::post('/',               [ItTaskController::class, 'store'])        ->name('store');
    //     Route::get('/{task}',          [ItTaskController::class, 'show'])         ->name('show');
    //     Route::get('/{task}/edit',     [ItTaskController::class, 'edit'])         ->name('edit');
    //     Route::put('/{task}',          [ItTaskController::class, 'update'])       ->name('update');
    //     Route::delete('/{task}',       [ItTaskController::class, 'destroy'])      ->name('destroy');
    //     Route::post('/{task}/comment',       [ItTaskController::class, 'addComment'])    ->name('comment');
    //     Route::post('/{task}/log-time',      [ItTaskController::class, 'logTime'])       ->name('log-time');
    //     Route::post('/{task}/update-status', [ItTaskController::class, 'updateStatus'])  ->name('update-status');
    // })->where('task', '[0-9]+');

    // ── Branch / Department → Azure Group Mappings ───────────────
    Route::prefix('identity/group-mappings')->name('admin.identity.group-mappings.')->middleware('permission:manage-identity')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\BranchDepartmentGroupController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\BranchDepartmentGroupController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\BranchDepartmentGroupController::class, 'store'])->name('store');
        Route::delete('/{groupMapping}', [\App\Http\Controllers\Admin\BranchDepartmentGroupController::class, 'destroy'])->name('destroy');
        Route::get('/preview', [\App\Http\Controllers\Admin\BranchDepartmentGroupController::class, 'preview'])->name('preview');
    });

    // ── Printer Deployment (employee-level link via employee show page) ───
    Route::post('printer-deploy/deploy', [\App\Http\Controllers\Admin\PrinterDeployController::class, 'deploy'])
        ->name('admin.printer-deploy.deploy')
        ->middleware('permission:manage-employees');
    Route::post('printer-deploy/intune', [\App\Http\Controllers\Admin\PrinterDeployController::class, 'deployToIntune'])
        ->name('admin.printer-deploy.intune')
        ->middleware('permission:manage-employees');
    Route::post('printer-deploy/intune-remove', [\App\Http\Controllers\Admin\PrinterDeployController::class, 'removeFromIntune'])
        ->name('admin.printer-deploy.intune-remove')
        ->middleware('permission:manage-employees');
    Route::get('printer-deploy/intune-preview', [\App\Http\Controllers\Admin\PrinterDeployController::class, 'intunePreview'])
        ->name('admin.printer-deploy.intune-preview')
        ->middleware('permission:manage-employees');
    Route::get('printer-deploy/script-preview', [\App\Http\Controllers\Admin\PrinterDeployController::class, 'scriptPreview'])
        ->name('admin.printer-deploy.script-preview')
        ->middleware('permission:manage-employees');

    // ── Intune Group Management ───────────────────────────────────────
    Route::prefix('intune-groups')->name('intune-groups.')->middleware('permission:manage-printers')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'store'])->name('store');
        Route::get('/users/search', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'searchUsers'])->name('users.search');
        Route::get('/groups/search', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'searchGroups'])->name('groups.search');
        Route::get('/{intuneGroup}', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'show'])->name('show');
        Route::delete('/{intuneGroup}', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'destroy'])->name('destroy');
        Route::post('/{intuneGroup}/members', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'addMember'])->name('members.add');
        Route::delete('/{intuneGroup}/members/{userId}', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'removeMember'])->name('members.remove');
        Route::post('/{intuneGroup}/deploy-printer', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'deployPrinter'])->name('deploy-printer');
        Route::post('/{intuneGroup}/sync-policies', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'syncPolicies'])->name('policies.sync');
        Route::delete('/{intuneGroup}/policies/{intuneGroupPolicy}', [\App\Http\Controllers\Admin\IntuneGroupController::class, 'removePolicy'])->name('policies.remove');
    });

    // ── Managed Wallpapers (Intune desktop + lock screen, per domain) ──
    Route::middleware('permission:view-wallpapers')->group(function () {
        Route::get('wallpapers', [\App\Http\Controllers\Admin\WallpaperController::class, 'index'])->name('wallpapers.index');
    });
    Route::middleware('permission:manage-wallpapers')->group(function () {
        Route::post('wallpapers', [\App\Http\Controllers\Admin\WallpaperController::class, 'store'])->name('wallpapers.store');
        Route::put('wallpapers/{wallpaper}', [\App\Http\Controllers\Admin\WallpaperController::class, 'update'])->name('wallpapers.update');
        Route::post('wallpapers/{wallpaper}/image', [\App\Http\Controllers\Admin\WallpaperController::class, 'uploadImage'])->name('wallpapers.image');
        Route::delete('wallpapers/{wallpaper}/image', [\App\Http\Controllers\Admin\WallpaperController::class, 'deleteImage'])->name('wallpapers.image.delete');
        Route::delete('wallpapers/{wallpaper}', [\App\Http\Controllers\Admin\WallpaperController::class, 'destroy'])->name('wallpapers.destroy');
    });

    // ── My Printers (SSO auto-assign — any authenticated user) ───────
    Route::get('my-printers', [\App\Http\Controllers\Admin\MyPrintersController::class, 'index'])
        ->name('admin.my-printers');

    // ── API Documentation ─────────────────────────────────────────────
    Route::get('api-docs', [\App\Http\Controllers\Admin\ApiDocsController::class, 'index'])
        ->name('admin.api-docs')
        ->middleware('permission:manage-settings');

    // ── HR API Key Manager ────────────────────────────────────────────
    Route::middleware('permission:manage-settings')->group(function () {
        Route::get('hr-api-keys', [HrApiKeyController::class, 'index'])->name('hr-api-keys.index');
        Route::post('hr-api-keys', [HrApiKeyController::class, 'store'])->name('hr-api-keys.store');
        Route::post('hr-api-keys/{hrApiKey}/revoke', [HrApiKeyController::class, 'revoke'])->name('hr-api-keys.revoke');
        Route::delete('hr-api-keys/{hrApiKey}', [HrApiKeyController::class, 'destroy'])->name('hr-api-keys.destroy');
    });

    // ── Admin Tools / Quick Links ──────────────────────────────────
    Route::middleware('permission:view-admin-links')->prefix('admin-links')->name('admin-links.')->group(function () {
        Route::get('/', [AdminLinkController::class, 'index'])->name('index');
        Route::get('/{adminLink}/go', [AdminLinkController::class, 'trackClick'])->name('go');
        Route::post('/{adminLink}/favorite', [AdminLinkController::class, 'toggleFavorite'])->name('favorite');
    });
    Route::middleware('permission:manage-admin-links')->prefix('admin-links')->name('admin-links.')->group(function () {
        Route::get('/manage', [AdminLinkController::class, 'manage'])->name('manage');
        Route::get('/create', [AdminLinkController::class, 'create'])->name('create');
        Route::post('/', [AdminLinkController::class, 'store'])->name('store');
        Route::get('/{adminLink}/edit', [AdminLinkController::class, 'edit'])->name('edit');
        Route::put('/{adminLink}', [AdminLinkController::class, 'update'])->name('update');
        Route::delete('/{adminLink}', [AdminLinkController::class, 'destroy'])->name('destroy');
        Route::post('/categories', [AdminLinkController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [AdminLinkController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [AdminLinkController::class, 'destroyCategory'])->name('categories.destroy');
    });

    // ─── Voice Quality ─────────────────────────────────────────────
    Route::prefix('voice-quality')->name('voice-quality.')->middleware('permission:view-voice-quality')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'dashboard'])->name('dashboard');
        Route::get('/stats', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'statistics'])->name('statistics');
        Route::get('/chart-data', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'chartData'])->name('chart-data');
        Route::get('/export', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'exportCsv'])->name('export');
        Route::get('/', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'index'])->name('index');
        Route::get('/{report}', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'show'])->name('show')->where('report', '[0-9]+');
    });

    // ─── Recruitment (Teamtailor candidates) ───────────────────────
    Route::prefix('candidates')->name('candidates.')->middleware('permission:view-candidates')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Teamtailor\CandidateController::class, 'index'])->name('index');
        Route::get('/{candidate}', [\App\Http\Controllers\Admin\Teamtailor\CandidateController::class, 'show'])->name('show');
    });

    // ─── Recruitment: Jobs & applicants (Teamtailor) ───────────────
    Route::prefix('jobs')->name('jobs.')->group(function () {
        Route::middleware('permission:view-candidates')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\Teamtailor\JobController::class, 'index'])->name('index');
            Route::get('/{job}', [\App\Http\Controllers\Admin\Teamtailor\JobController::class, 'show'])->name('show');
            // Bulk CV export → Azure Blob. The POST queues a TeamtailorCvExport
            // for the scheduled command to build; the download proxies the
            // finished zip (candidate PII — never a public blob link).
            Route::post('/{job}/cv-exports', [\App\Http\Controllers\Admin\Teamtailor\JobController::class, 'exportCvs'])
                ->name('cv-exports.store');
            Route::get('/{job}/cv-exports/{export}/download', [\App\Http\Controllers\Admin\Teamtailor\JobController::class, 'downloadCvExport'])
                ->name('cv-exports.download');
        });
        // Rejecting writes to the live ATS — gated separately from viewing.
        Route::middleware('permission:reject-candidates')->group(function () {
            Route::post('/{job}/applications/{application}/reject', [\App\Http\Controllers\Admin\Teamtailor\JobController::class, 'reject'])
                ->name('applications.reject');
        });
    });

    // ─── Switch Drops — RETIRED ────────────────────────────────────
    // Superseded by Switch QoS dashboard. Controller preserved; routes
    // disabled so /admin/switch-drops/* 404s. To re-enable, un-comment.
    // Route::prefix('switch-drops')->name('switch-drops.')->middleware('permission:view-voice-quality')->group(function () {
    //     Route::get('/dashboard',    [\App\Http\Controllers\Admin\SwitchDropController::class, 'dashboard'])  ->name('dashboard');
    //     Route::get('/stats',        [\App\Http\Controllers\Admin\SwitchDropController::class, 'statistics']) ->name('statistics');
    //     Route::get('/export',       [\App\Http\Controllers\Admin\SwitchDropController::class, 'exportCsv'])  ->name('export');
    //     Route::get('/',             [\App\Http\Controllers\Admin\SwitchDropController::class, 'index'])      ->name('index');
    //     Route::get('/device/{ip}',  [\App\Http\Controllers\Admin\SwitchDropController::class, 'device'])     ->name('device')->where('ip', '[0-9a-fA-F.:]+');
    // });

    // ─── Switch QoS (Cisco MLS QoS queue drops) ───────────────────
    Route::prefix('switch-qos')->name('switch-qos.')->middleware('permission:view-voice-quality')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\SwitchQosController::class, 'dashboard'])->name('dashboard');
        Route::get('/topology', [\App\Http\Controllers\Admin\SwitchQosController::class, 'topology'])->name('topology');
        Route::get('/cdp', [\App\Http\Controllers\Admin\SwitchQosController::class, 'cdpIndex'])->name('cdp');
        Route::get('/export', [\App\Http\Controllers\Admin\SwitchQosController::class, 'exportCsv'])->name('export');
        Route::get('/', [\App\Http\Controllers\Admin\SwitchQosController::class, 'index'])->name('index');
        Route::get('/device/{ip}/compare', [\App\Http\Controllers\Admin\SwitchQosController::class, 'compare'])->name('compare')->where('ip', '[0-9a-fA-F.:]+');
        Route::get('/device/{ip}', [\App\Http\Controllers\Admin\SwitchQosController::class, 'device'])->name('device')->where('ip', '[0-9a-fA-F.:]+');
        Route::get('/setup/{device}', [\App\Http\Controllers\Admin\SwitchQosController::class, 'setup'])->name('setup');

        // Running-config archive (viewable by anyone with view-voice-quality).
        Route::get('/configs', [\App\Http\Controllers\Admin\SwitchQosController::class, 'configsIndex'])->name('configs.index');
        Route::get('/configs/{device}', [\App\Http\Controllers\Admin\SwitchQosController::class, 'configShow'])->name('configs.show');
        Route::get('/configs/{device}/snapshot/{snapshotId}', [\App\Http\Controllers\Admin\SwitchQosController::class, 'configShow'])->name('configs.snapshot');
        Route::get('/configs/{device}/snapshot/{snapshotId}/download', [\App\Http\Controllers\Admin\SwitchQosController::class, 'configDownload'])->name('configs.download');
        Route::get('/configs/{device}/diff', [\App\Http\Controllers\Admin\SwitchQosController::class, 'configDiff'])->name('configs.diff');

        // Credential management + connectivity probe — admins only (manage-credentials).
        Route::middleware('permission:manage-credentials')->group(function () {
            Route::post('/device/{device}/test', [\App\Http\Controllers\Admin\SwitchQosController::class, 'testConnection'])->name('test');
            Route::post('/device/{device}/poll', [\App\Http\Controllers\Admin\SwitchQosController::class, 'pollNow'])->name('poll');
            Route::post('/device/{device}/clear-stats', [\App\Http\Controllers\Admin\SwitchQosController::class, 'clearStats'])->name('clear');
            Route::post('/clear-all-stats', [\App\Http\Controllers\Admin\SwitchQosController::class, 'clearAllStats'])->name('clear.all');
            Route::post('/device/{device}/fetch-config', [\App\Http\Controllers\Admin\SwitchQosController::class, 'fetchConfig'])->name('configs.fetch');
            Route::post('/fetch-all-configs', [\App\Http\Controllers\Admin\SwitchQosController::class, 'fetchAllConfigs'])->name('configs.fetch.all');
            Route::get('/device/{device}/telnet', [\App\Http\Controllers\Admin\SwitchQosController::class, 'telnetConsole'])->name('telnet');
            Route::post('/device/{device}/credentials', [\App\Http\Controllers\Admin\SwitchQosController::class, 'saveCredential'])->name('credentials.save');
            Route::delete('/device/{device}/credentials/{credential}', [\App\Http\Controllers\Admin\SwitchQosController::class, 'deleteCredential'])->name('credentials.delete');
        });
    });

    // ─── Form Builder (admin) ──────────────────────────────────────
    Route::middleware('permission:manage-forms')->prefix('forms')->name('forms.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\FormBuilderController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\FormBuilderController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\FormBuilderController::class, 'store'])->name('store');
        Route::get('/{form}/edit', [\App\Http\Controllers\Admin\FormBuilderController::class, 'edit'])->name('edit');
        Route::put('/{form}', [\App\Http\Controllers\Admin\FormBuilderController::class, 'update'])->name('update');
        Route::delete('/{form}', [\App\Http\Controllers\Admin\FormBuilderController::class, 'destroy'])->name('destroy');
        Route::get('/{form}/submissions/export', [\App\Http\Controllers\Admin\FormBuilderController::class, 'exportSubmissions'])->name('export');
        Route::get('/{form}/submissions/{submission}', [\App\Http\Controllers\Admin\FormBuilderController::class, 'showSubmission'])->name('submission.show');
        Route::patch('/{form}/submissions/{submission}', [\App\Http\Controllers\Admin\FormBuilderController::class, 'reviewSubmission'])->name('submission.review');
        Route::get('/{form}/submissions', [\App\Http\Controllers\Admin\FormBuilderController::class, 'submissions'])->name('submissions');
        Route::post('/{form}/tokens', [\App\Http\Controllers\Admin\FormBuilderController::class, 'generateToken'])->name('tokens.generate');
    });

    // ─── Documentation (HTML report/doc upload & viewer) ──────────
    // NOTE: static segment 'documentation/{filename}/raw' MUST come before
    // the show route to prevent Laravel matching 'raw' as a filename wildcard.
    Route::middleware('permission:view-documentation')->prefix('documentation')->name('documentation.')->group(function () {
        Route::get('/', [DocumentationController::class, 'index'])->name('index');
        Route::get('/{filename}/raw', [DocumentationController::class, 'raw'])->name('raw');
        Route::get('/{filename}', [DocumentationController::class, 'show'])->name('show');
    });
    Route::middleware('permission:manage-documentation')->prefix('documentation')->name('documentation.')->group(function () {
        Route::post('/', [DocumentationController::class, 'store'])->name('store');
        Route::post('/{filename}/toggle-public', [DocumentationController::class, 'togglePublic'])->name('toggle-public');
        Route::post('/{filename}/meta', [DocumentationController::class, 'updateMeta'])->name('update-meta');
        Route::delete('/{filename}', [DocumentationController::class, 'destroy'])->name('destroy');
    });

    // ─── Email Signature Templates ─────────────────────────────────
    Route::prefix('signatures')->name('signatures.')->group(function () {
        // Preview endpoints are accessible to anyone who can view-admin-links (editors need them)
        Route::post('/preview', [\App\Http\Controllers\Admin\SignatureController::class, 'preview'])->name('preview');
        Route::post('/preview-saved', [\App\Http\Controllers\Admin\SignatureController::class, 'previewSaved'])->name('preview-saved');

        Route::middleware('permission:manage-signatures')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SignatureController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\SignatureController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\SignatureController::class, 'store'])->name('store');
            Route::get('/{signature}/edit', [\App\Http\Controllers\Admin\SignatureController::class, 'edit'])->name('edit');
            Route::put('/{signature}', [\App\Http\Controllers\Admin\SignatureController::class, 'update'])->name('update');
            Route::delete('/{signature}', [\App\Http\Controllers\Admin\SignatureController::class, 'destroy'])->name('destroy');
            Route::post('/{signature}/duplicate', [\App\Http\Controllers\Admin\SignatureController::class, 'duplicate'])->name('duplicate');
        });
    });

});

// Email signature API — called by Intune device scripts and the Graph nightly job.
// GET /api/signature?upn=user@domain.com&type=new_email&api_key=…
// Auth: SIGNATURE_API_KEY in .env (skip key check if env var is not set)
Route::get('/api/signature', [\App\Http\Controllers\Admin\SignatureController::class, 'apiRender'])
    ->middleware('throttle:120,1')
    ->name('api.signature');

// Transport-rule HTML (New Outlook / OWA / mobile) — consumed by Deploy-TransportRules.ps1.
// GET /api/signature/transport-rule?domain=sssegypt.com&type=new_email&api_key=…
Route::get('/api/signature/transport-rule', [\App\Http\Controllers\Admin\SignatureController::class, 'transportRuleHtml'])
    ->middleware('throttle:120,1')
    ->name('api.signature.transport-rule');

// Gendered UPN list for populating the per-gender Exchange groups (Deploy-TransportRules.ps1).
Route::get('/api/signature/gender-members', [\App\Http\Controllers\Admin\SignatureController::class, 'genderMembers'])
    ->middleware('throttle:120,1')
    ->name('api.signature.gender-members');

// Internal VQ report endpoint
Route::post('/api/internal/vq-report', [\App\Http\Controllers\Admin\VoiceQualityController::class, 'receive'])
    ->middleware('internal.ip')
    ->name('api.vq-report');

// Graylog webhook — receives alert notifications from Event Definitions
// and turns them into NocEvent rows so the existing notification routing
// fires. Auth is via X-Graylog-Secret header (see config/services.php).
Route::post('/api/graylog/webhook', \App\Http\Controllers\Api\GraylogWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.graylog.webhook');

// SFTPGo fires this on every device upload (X-Backup-Secret shared secret).
// CSRF-exempt (bootstrap/app.php); stamps the matching BackupAccount as received.
Route::post('/api/backup/upload-hook', \App\Http\Controllers\Api\BackupUploadWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('api.backup.upload-hook');

// Branch-config endpoints — branch VMs PULL their device list, POST nmap
// discoveries. Auth is via Bearer token (the per-branch api_token kept
// on branch_log_collectors). No session middleware → CSRF-exempt.
Route::prefix('api/branch-config')
    ->name('api.branch-config.')
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::get('snmp-devices', [\App\Http\Controllers\Api\BranchConfigController::class, 'snmpDevices'])->name('snmp-devices');
        Route::post('discovered-devices', [\App\Http\Controllers\Api\BranchConfigController::class, 'postDiscovered'])->name('discovered-devices');
    });

// Branch-agent endpoints — sg-branch-agent enrolls (one-time code → token),
// then heartbeats, reports its WAN IP (DDNS) and pulls config with its Bearer
// token. CSRF-exempt (machine-to-machine, no session).
Route::prefix('api/branch-agents')
    ->name('api.branch-agents.')
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::post('enroll', [\App\Http\Controllers\Api\BranchAgentController::class, 'enroll'])->name('enroll');
        Route::post('heartbeat', [\App\Http\Controllers\Api\BranchAgentController::class, 'heartbeat'])->name('heartbeat');
        Route::post('ddns', [\App\Http\Controllers\Api\BranchAgentController::class, 'ddns'])->name('ddns');
        Route::get('config', [\App\Http\Controllers\Api\BranchAgentController::class, 'config'])->name('config');
    });

// Public branch-agent download artifacts so the one-line installer works on a
// bare VM (no auth). Read-only files (installer script + prebuilt binary +
// checksum); no secrets.
Route::prefix('branch-agent')
    ->name('branch-agent.')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('install.sh', [\App\Http\Controllers\BranchAgentDownloadController::class, 'install'])->name('install');
        Route::get('sg-branch-agent', [\App\Http\Controllers\BranchAgentDownloadController::class, 'binary'])->name('binary');
        Route::get('sg-branch-agent.sha256', [\App\Http\Controllers\BranchAgentDownloadController::class, 'sha256'])->name('sha256');
    });

/*
|--------------------------------------------------------------------------
| Public Routes (no auth required)
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Public\OffboardingFormController;
use App\Http\Controllers\Public\OnboardingFormController;
use App\Http\Controllers\Public\PrinterSetupController;

// Offboarding manager approval form
Route::get('/offboarding/respond', [OffboardingFormController::class, 'show'])->name('offboarding.form');
Route::post('/offboarding/respond', [OffboardingFormController::class, 'submit'])->name('offboarding.submit')->middleware('throttle:5,1');

// Offboarding backup download (NOC-proxied stream from Azure Blob)
Route::get('/offboarding/download/{token}', [\App\Http\Controllers\Public\OffboardingDownloadController::class, 'download'])
    ->name('offboarding.download')
    ->middleware('throttle:5,1')
    ->where('token', '[A-Za-z0-9]{64}');

// AvePoint ad-hoc backup download (NOC-proxied stream from Azure Blob, azure_avepoint disk)
Route::get('/avepoint/download/{token}', [\App\Http\Controllers\Public\AvepointDownloadController::class, 'download'])
    ->name('avepoint.download')
    ->middleware('throttle:5,1')
    ->where('token', '[A-Za-z0-9]{64}');

// Download Center public share links (token-based, NOC-proxied stream from Azure)
Route::get('/d/{token}', [\App\Http\Controllers\Public\DownloadShareController::class, 'show'])
    ->name('downloads.share')
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{40}');
Route::get('/d/{token}/file', [\App\Http\Controllers\Public\DownloadShareController::class, 'stream'])
    ->name('downloads.share.download')
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{40}');

// Managed-wallpaper deployment — consumed by Intune devices, unauthenticated.
// Manifest = per-domain image URLs + hashes; script = the PowerShell agent with
// the manifest URL baked in. See WallpaperDeploymentController for the rationale.
Route::get('/api/wallpapers/manifest', [\App\Http\Controllers\Public\WallpaperDeploymentController::class, 'manifest'])
    ->name('wallpapers.manifest')
    ->middleware('throttle:120,1');
Route::get('/api/wallpapers/script.ps1', [\App\Http\Controllers\Public\WallpaperDeploymentController::class, 'script'])
    ->name('wallpapers.script')
    ->middleware('throttle:120,1');
Route::post('/api/wallpapers/checkin', [\App\Http\Controllers\Public\WallpaperDeploymentController::class, 'checkin'])
    ->name('wallpapers.checkin')
    ->middleware('throttle:120,1');

// Onboarding manager setup form (token-based, public)
Route::get('/onboarding/form/{token}', [OnboardingFormController::class, 'show'])->name('onboarding.form');
Route::post('/onboarding/form/{token}', [OnboardingFormController::class, 'submit'])->name('onboarding.submit')->middleware('throttle:10,1');

// Public & token-only forms
Route::get('/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'show'])->name('forms.show');
Route::post('/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'submit'])->name('forms.submit')->middleware('throttle:20,1');

// Private (logged-in employee) forms
Route::middleware('auth')->group(function () {
    Route::get('/my/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'show'])->name('forms.private.show');
    Route::post('/my/forms/{slug}', [\App\Http\Controllers\Public\PublicFormController::class, 'submit'])->name('forms.private.submit');
});

// CUPS AirPrint profile download (public — scanned via QR on mobile devices)
Route::middleware(['throttle:20,1'])->group(function () {
    Route::get('/airprint/{cupsPrinter}', [CupsPrinterController::class, 'airprintProfile'])->name('admin.print-manager.airprint');
});

// Printer self-service setup
Route::middleware(['throttle:20,1'])->group(function () {
    Route::get('/printer-setup', [PrinterSetupController::class, 'show'])->name('printer.setup');
    Route::get('/printer-setup/script', [PrinterSetupController::class, 'downloadScript'])->name('printer.setup.script');
    Route::get('/printer-setup/download', [PrinterSetupController::class, 'downloadScript'])->name('printer.setup.download');
    Route::get('/printer-setup/driver', [PrinterSetupController::class, 'downloadDriver'])->name('printer.setup.driver');
    Route::get('/printer-setup/download-driver', [PrinterSetupController::class, 'downloadDriver'])->name('printer.setup.download-driver');
});

/*
|--------------------------------------------------------------------------
| HR API Routes (token-authenticated, no session)
|--------------------------------------------------------------------------
*/

Route::prefix('api/hr')
    ->middleware('hr.api_key')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
    ->group(function () {
        Route::post('/onboarding', [HrOnboardingController::class,      'store'])->name('api.hr.onboarding');
        Route::post('/offboarding', [HrOffboardingController::class,     'store'])->name('api.hr.offboarding');
        Route::post('/group-assignment', [HrGroupAssignmentController::class, 'store'])->name('api.hr.group-assignment');
        Route::get('/device-lookup', [DeviceLookupController::class,      'lookup'])->name('api.hr.device-lookup');
    });

/*
|--------------------------------------------------------------------------
| Internal — Telnet Token Validation (called only by the Node.js proxy)
| Protected by: localhost-only + X-Telnet-Secret header check in controller.
|--------------------------------------------------------------------------
*/
Route::get('/internal/telnet-token/{token}',
    [\App\Http\Controllers\Internal\TelnetTokenController::class, 'show']
)->middleware('internal.ip')->name('internal.telnet-token');

/*
|--------------------------------------------------------------------------
| Auth Routes (Laravel Breeze)
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
