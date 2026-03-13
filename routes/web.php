<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ExtensionController;
use App\Http\Controllers\Admin\TrunkController;
use App\Http\Controllers\Admin\UcmServerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GdmsController;
use App\Http\Controllers\Admin\NetworkController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DeviceModelController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrinterMaintenanceController;
use App\Http\Controllers\Admin\IdentityController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\NocController;
use App\Http\Controllers\Admin\WorkflowTemplateController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\NotificationRuleController;
use App\Http\Controllers\Admin\LicenseMonitorController;
use App\Http\Controllers\Admin\AllowedDomainController;
use App\Http\Controllers\Admin\EmployeeItemController;
use App\Http\Controllers\Admin\VpnHubController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\SnmpMonitoringController;
use App\Http\Controllers\Admin\WorkersDashboardController;
use App\Http\Controllers\Admin\IpScannerController;
use App\Http\Controllers\Admin\IspConnectionController;
use App\Http\Controllers\Admin\IpReservationController;
use App\Http\Controllers\Admin\LandlineController;
use App\Http\Controllers\Admin\SlaController;
use App\Http\Controllers\Admin\TopologyController;
use App\Http\Controllers\Admin\WarrantyTrackerController;
use App\Http\Controllers\Admin\PortMapController;
use App\Http\Controllers\Admin\DhcpLeaseController;
use App\Http\Controllers\Admin\SophosFirewallController;
use App\Http\Controllers\Admin\IpamController;
use App\Http\Controllers\Admin\ItamController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\AccessoryController;
use App\Http\Controllers\Admin\AzureSyncController;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\PhonebookController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\PhoneRequestLogController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phonebook.xml', [PhonebookController::class, 'generate'])
    ->withoutMiddleware(['web'])
    ->name('phonebook.xml');

Route::get('/contacts', [PublicContactController::class, 'index'])
    ->name('public.contacts');

Route::get('/contacts/print', [PublicContactController::class, 'print'])
    ->name('public.contacts.print');
// Compact print layout (landscape)
Route::get('/contacts/print-compact', [PhonebookController::class, 'printCompact'])->name('public.contacts.print.compact');
/*
|--------------------------------------------------------------------------
| Microsoft SSO
|--------------------------------------------------------------------------
*/

Route::get('/auth/microsoft', [MicrosoftController::class, 'redirect'])
    ->name('auth.microsoft');
Route::get('/auth/microsoft/callback', [MicrosoftController::class, 'callback']);

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

    // Dashboard (all authenticated users)
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

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

    // ─── Settings ─────────────────────────────────────────────
    Route::middleware('permission:manage-settings')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])
            ->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])
            ->name('settings.update');
        Route::delete('settings/logo', [SettingsController::class, 'deleteLogo'])
            ->name('settings.delete-logo');
        Route::post('settings/sso', [SettingsController::class, 'updateSso'])
            ->name('settings.sso');
        Route::post('settings/meraki', [SettingsController::class, 'updateMeraki'])
            ->name('settings.meraki');
        Route::post('settings/graph', [SettingsController::class, 'updateGraph'])
            ->name('settings.graph');
        Route::post('settings/gdms', [SettingsController::class, 'updateGdms'])
            ->name('settings.gdms');
        // Test-connection buttons live on the Settings page — accessible to any settings manager
        Route::post('settings/test-meraki',  [NetworkController::class,  'testConnection'])->name('settings.test-meraki');
        Route::post('settings/test-graph',   [IdentityController::class, 'testConnection'])->name('settings.test-graph');

        // ── Sync Status Dashboard ────────────────────────────────────
        Route::get('sync-status',                    [\App\Http\Controllers\Admin\SyncStatusController::class, 'index'])          ->name('sync-status');
        Route::post('sync-status/intervals',         [\App\Http\Controllers\Admin\SyncStatusController::class, 'updateIntervals'])->name('sync-status.intervals');
        Route::post('sync-status/trigger',           [\App\Http\Controllers\Admin\SyncStatusController::class, 'triggerSync'])    ->name('sync-status.trigger');

        // ── Locations (all 4 tiers: branches, floors, racks, offices) ──
        Route::get('settings/locations',                          [SettingsController::class, 'locations'])       ->name('settings.locations');

        // Branches (modal-based, same page)
        Route::post('settings/branches',                          [BranchController::class, 'store'])             ->name('settings.branches.store');
        Route::put('settings/branches/{branch}',                  [BranchController::class, 'update'])            ->name('settings.branches.update');
        Route::delete('settings/branches/{branch}',               [BranchController::class, 'destroy'])           ->name('settings.branches.destroy');

        // Floors (same CRUD as before, also accessible from settings)
        Route::post('settings/floors',                            [NetworkController::class, 'storeFloor'])       ->name('settings.floors.store');
        Route::put('settings/floors/{floor}',                     [NetworkController::class, 'updateFloor'])      ->name('settings.floors.update');
        Route::delete('settings/floors/{floor}',                  [NetworkController::class, 'destroyFloor'])     ->name('settings.floors.destroy');

        // Racks
        Route::post('settings/racks',                             [NetworkController::class, 'storeRack'])        ->name('settings.racks.store');
        Route::put('settings/racks/{rack}',                       [NetworkController::class, 'updateRack'])       ->name('settings.racks.update');
        Route::delete('settings/racks/{rack}',                    [NetworkController::class, 'destroyRack'])      ->name('settings.racks.destroy');

        // Offices (new tier)
        Route::post('settings/offices',                           [NetworkController::class, 'storeOffice'])      ->name('settings.offices.store');
        Route::put('settings/offices/{office}',                   [NetworkController::class, 'updateOffice'])     ->name('settings.offices.update');
        Route::delete('settings/offices/{office}',                [NetworkController::class, 'destroyOffice'])    ->name('settings.offices.destroy');

        // ── Departments ──────────────────────────────────────────────
        Route::get('settings/departments',                        [SettingsController::class, 'departments'])     ->name('settings.departments');
        Route::post('settings/departments',                       [SettingsController::class, 'storeDepartment']) ->name('settings.departments.store');
        Route::put('settings/departments/{department}',           [SettingsController::class, 'updateDepartment'])->name('settings.departments.update');
        Route::delete('settings/departments/{department}',        [SettingsController::class, 'destroyDepartment'])->name('settings.departments.destroy');

        // ── SMTP / Outgoing Mail ──────────────────────────────────────
        Route::post('settings/itam',      [SettingsController::class, 'updateItam'])  ->name('settings.itam');
        Route::post('settings/smtp',      [SettingsController::class, 'updateSmtp']) ->name('settings.smtp');
        Route::post('settings/test-smtp', [SettingsController::class, 'testSmtp'])   ->name('settings.test-smtp');

        // ── Allowed Domains ──────────────────────────────────────────
        Route::get('settings/domains',                              [\App\Http\Controllers\Admin\AllowedDomainController::class, 'index'])      ->name('settings.domains');
        Route::post('settings/domains',                             [\App\Http\Controllers\Admin\AllowedDomainController::class, 'store'])      ->name('settings.domains.store');
        Route::delete('settings/domains/{allowedDomain}',          [\App\Http\Controllers\Admin\AllowedDomainController::class, 'destroy'])    ->name('settings.domains.destroy');
        Route::patch('settings/domains/{allowedDomain}/set-primary', [\App\Http\Controllers\Admin\AllowedDomainController::class, 'setPrimary'])->name('settings.domains.set-primary');
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
    });

    // ─── GDMS UCM Status ──────────────────────────────────────
    Route::middleware('permission:view-extensions')->group(function () {
        Route::get('gdms/ucm', [GdmsController::class, 'ucmIndex'])
            ->name('gdms.ucm');
    });

    // ─── Role Permissions ─────────────────────────────────────
    Route::middleware('permission:manage-permissions')->group(function () {
        Route::get('permissions', [PermissionsController::class, 'index'])
            ->name('permissions.index');
        Route::put('permissions', [PermissionsController::class, 'update'])
            ->name('permissions.update');
    });

    // ─── Device Models ────────────────────────────────────────
    Route::middleware('permission:view-assets')->group(function () {
        Route::get('devices/models',  [DeviceModelController::class, 'index']) ->name('devices.models.index');
    });
    Route::middleware('permission:manage-assets')->group(function () {
        Route::post('devices/models',                  [DeviceModelController::class, 'store'])   ->name('devices.models.store');
        Route::put('devices/models/{deviceModel}',     [DeviceModelController::class, 'update'])  ->name('devices.models.update');
        Route::delete('devices/models/{deviceModel}',  [DeviceModelController::class, 'destroy']) ->name('devices.models.destroy');
    });

    // ─── Devices (Assets) ─────────────────────────────────────
    Route::middleware('permission:view-assets')->group(function () {
        Route::get('devices',                  [DeviceController::class, 'index'])     ->name('devices.index');
        Route::get('devices/create',           [DeviceController::class, 'create'])    ->name('devices.create');
        Route::get('devices/scan',             [DeviceController::class, 'scan'])         ->name('devices.scan');
        Route::get('devices/generate-code',    [DeviceController::class, 'generateCode']) ->name('devices.generate-code');
        Route::get('devices/warranty',         [WarrantyTrackerController::class, 'index'])->name('devices.warranty');
        Route::get('devices/firmware',         [DeviceController::class, 'firmware'])  ->name('devices.firmware');
        Route::get('devices/{device}/label',   [DeviceController::class, 'label'])     ->name('devices.label');
        Route::get('devices/{device}/edit',    [DeviceController::class, 'edit'])      ->name('devices.edit');
        Route::get('devices/{device}',         [DeviceController::class, 'show'])      ->name('devices.show');
    });
    Route::middleware('permission:manage-assets')->group(function () {
        Route::post('devices',                     [DeviceController::class, 'store'])       ->name('devices.store');
        Route::put('devices/{device}',             [DeviceController::class, 'update'])      ->name('devices.update');
        Route::delete('devices/{device}',          [DeviceController::class, 'destroy'])     ->name('devices.destroy');
        Route::post('devices/{device}/assign',     [DeviceController::class, 'quickAssign']) ->name('devices.assign');
        Route::post('devices/{device}/return',     [DeviceController::class, 'quickReturn']) ->name('devices.return');
    });

    // ─── Credentials (Password Vault) ─────────────────────────
    Route::middleware('permission:view-credentials')->group(function () {
        Route::get('credentials',                     [CredentialController::class, 'index'])   ->name('credentials.index');
        Route::get('credentials/generate',            [CredentialController::class, 'generate']) ->name('credentials.generate');
        Route::get('credentials/create',              [CredentialController::class, 'create'])   ->name('credentials.create');
        Route::get('credentials/{credential}/edit',   [CredentialController::class, 'edit'])     ->name('credentials.edit');
        // Reveal password: GET (read-only, permission-gated in controller)
        Route::get('credentials/{credential}/reveal', [CredentialController::class, 'reveal'])   ->name('credentials.reveal');
    });
    Route::middleware('permission:manage-credentials')->group(function () {
        Route::post('credentials',                            [CredentialController::class, 'store'])    ->name('credentials.store');
        Route::put('credentials/{credential}',                [CredentialController::class, 'update'])   ->name('credentials.update');
        Route::delete('credentials/{credential}',             [CredentialController::class, 'destroy'])  ->name('credentials.destroy');
        Route::post('credentials/{credential}/log-copy',      [CredentialController::class, 'logCopy'])  ->name('credentials.log-copy');
    });

    // ─── Printers ─────────────────────────────────────────────
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('printers',                 [PrinterController::class, 'index'])  ->name('printers.index');
        Route::get('printers/create',          [PrinterController::class, 'create'])  ->name('printers.create');
        Route::get('printers/{printer}/edit',  [PrinterController::class, 'edit'])    ->name('printers.edit');
        Route::get('printers/{printer}',       [PrinterController::class, 'show'])   ->name('printers.show');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('printers',                [PrinterController::class, 'store'])   ->name('printers.store');
        Route::put('printers/{printer}',       [PrinterController::class, 'update'])  ->name('printers.update');
        Route::delete('printers/{printer}',    [PrinterController::class, 'destroy']) ->name('printers.destroy');
    });

    // ─── Identity (Entra ID / Graph API) ──────────────────────
    Route::middleware('permission:view-identity')->prefix('identity')->name('identity.')->group(function () {
        Route::get('/users',                          [IdentityController::class, 'users'])        ->name('users');
        Route::get('/users/{azureId}',              [IdentityController::class, 'userDetail'])  ->name('user');
        Route::get('/licenses',                     [IdentityController::class, 'licenses'])     ->name('licenses');
        Route::get('/groups',                       [IdentityController::class, 'groups'])       ->name('groups');
        Route::get('/groups/{azureId}/members',     [IdentityController::class, 'groupMembers'])->name('group.members');
        Route::get('/sync-logs',                    [IdentityController::class, 'syncLogs'])     ->name('sync-logs');
    });
    Route::middleware('permission:manage-identity')->prefix('identity')->name('identity.')->group(function () {
        Route::post('/sync',                                     [IdentityController::class, 'sync'])           ->name('sync');
        Route::patch('/users/{azureId}/toggle',                  [IdentityController::class, 'toggleUser'])     ->name('user.toggle');
        Route::patch('/users/{azureId}/reset-password',          [IdentityController::class, 'resetPassword'])  ->name('user.reset-password');
        Route::patch('/users/{azureId}/profile',                 [IdentityController::class, 'updateProfile'])  ->name('user.update-profile');
        Route::post('/users/{azureId}/assign-license',           [IdentityController::class, 'assignLicense'])  ->name('user.assign-license');
        Route::delete('/users/{azureId}/remove-license',         [IdentityController::class, 'removeLicense'])  ->name('user.remove-license');
        Route::post('/users/{azureId}/add-group',                [IdentityController::class, 'addGroup'])       ->name('user.add-group');
        Route::delete('/users/{azureId}/remove-group',           [IdentityController::class, 'removeGroup'])    ->name('user.remove-group');
        Route::delete('/users/{azureId}/delete',                 [IdentityController::class, 'destroyUser'])    ->name('user.destroy');
    });
    Route::middleware('permission:manage-identity-settings')->prefix('identity')->name('identity.')->group(function () {
        Route::post('/test-connection',  [IdentityController::class, 'testConnection']) ->name('test-connection');
    });

    // ─── Network (Meraki) ─────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network')->name('network.')->group(function () {
        Route::get('/',              [NetworkController::class, 'overview'])    ->name('overview');
        Route::get('/switches',      [NetworkController::class, 'switches'])    ->name('switches');
        Route::get('/switches/{serial}', [NetworkController::class, 'switchDetail'])->name('switch-detail');
        Route::get('/clients',       [NetworkController::class, 'clients'])     ->name('clients');
        Route::get('/sync-logs',     [NetworkController::class, 'syncLogs'])   ->name('sync-logs');
        // MAC search for autocomplete in asset/printer forms
        Route::get('/clients/mac-search', [NetworkController::class, 'macSearch'])->name('clients.mac-search');
        // Offices AJAX (public within view-network so asset forms can populate options)
        Route::get('/offices',       [NetworkController::class, 'officesByFloor'])->name('offices');
        Route::get('/floors',        [NetworkController::class, 'floorsByBranch'])->name('floors');
    });

    Route::middleware('permission:view-network-events')->prefix('network')->name('network.')->group(function () {
        Route::get('/events',        [NetworkController::class, 'events'])      ->name('events');
    });

    Route::middleware('permission:manage-network-settings')->prefix('network')->name('network.')->group(function () {
        // GET sync redirect (prevents MethodNotAllowed when someone navigates directly via URL)
        Route::get('/sync',          fn() => redirect()->route('admin.network.overview'))->name('sync.redirect');
        Route::post('/sync',         [NetworkController::class, 'sync'])        ->name('sync');
        Route::post('/test-connection', [NetworkController::class, 'testConnection'])->name('test-connection');

        // ── Uplink port management ───────────────────────────────
        Route::patch('/switches/{serial}/uplink-ports', [NetworkController::class, 'setUplinkPorts'])->name('switches.uplink-ports');

        // ── Legacy location management (kept for backward compat) ──
        Route::post('/floors',                            [NetworkController::class, 'storeFloor'])   ->name('floors.store');
        Route::put('/floors/{floor}',                     [NetworkController::class, 'updateFloor'])  ->name('floors.update');
        Route::delete('/floors/{floor}',                  [NetworkController::class, 'destroyFloor']) ->name('floors.destroy');

        Route::post('/racks',                             [NetworkController::class, 'storeRack'])    ->name('racks.store');
        Route::put('/racks/{rack}',                       [NetworkController::class, 'updateRack'])   ->name('racks.update');
        Route::delete('/racks/{rack}',                    [NetworkController::class, 'destroyRack'])  ->name('racks.destroy');

        Route::post('/switches/{serial}/assign-location', [NetworkController::class, 'assignLocation'])->name('switches.assign-location');
    });

    // ─── VPN Hub ──────────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/vpn')->name('network.vpn.')->group(function () {
        Route::get('/',                 [VpnHubController::class, 'index'])->name('index');
        Route::get('/create',           [VpnHubController::class, 'create'])->name('create');
        Route::post('/',                [VpnHubController::class, 'store'])->name('store');
        Route::get('/{tunnel}/edit',    [VpnHubController::class, 'edit'])->name('edit');
        Route::put('/{tunnel}',         [VpnHubController::class, 'update'])->name('update');
        Route::delete('/{tunnel}',      [VpnHubController::class, 'destroy'])->name('destroy');
        Route::post('/{tunnel}/up',     [VpnHubController::class, 'initiate'])->name('up');
        Route::post('/{tunnel}/down',   [VpnHubController::class, 'terminate'])->name('down');
        Route::post('/reload',          [VpnHubController::class, 'reload'])->name('reload');
        Route::get('/logs',             [VpnHubController::class, 'showLogs'])->name('logs');
        Route::get('/{tunnel}/status',  [VpnHubController::class, 'checkStatus'])->name('status');
    });

    // ─── Diagnostics ──────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/diagnostics')->name('network.diagnostics.')->group(function () {
        Route::get('/',         [DiagnosticsController::class, 'index'])->name('index');
        Route::post('/ping',    [DiagnosticsController::class, 'ping'])->name('ping');
        Route::post('/tcp-check', [DiagnosticsController::class, 'tcpCheck'])->name('tcp-check');
    });

    // ─── SNMP Monitoring ──────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/monitoring')->name('network.monitoring.')->group(function () {
        Route::get('/',             [SnmpMonitoringController::class, 'index'])->name('index');
        Route::get('/hosts/{host}', [SnmpMonitoringController::class, 'show'])->name('show');
        Route::get('/hosts/{host}/settings', [SnmpMonitoringController::class, 'settings'])->name('hosts.settings');
        Route::post('/hosts/{host}/discover-device', [SnmpMonitoringController::class, 'discoverDevice'])->name('hosts.discover-device');
        Route::post('/hosts/{host}/discover-interfaces', [SnmpMonitoringController::class, 'discoverInterfaces'])->name('hosts.discover-interfaces');
        Route::post('/hosts/{host}/sensors', [SnmpMonitoringController::class, 'storeSensor'])->name('hosts.sensors.store');
        Route::delete('/hosts/{host}/sensors/{sensor}', [SnmpMonitoringController::class, 'destroySensor'])->name('hosts.sensors.destroy');
        Route::post('/hosts',       [SnmpMonitoringController::class, 'storeHost'])->name('hosts.store');
        Route::put('/hosts/{host}', [SnmpMonitoringController::class, 'updateHost'])->name('hosts.update');
        Route::post('/hosts/{host}/ping', [SnmpMonitoringController::class, 'pingHost'])->name('hosts.ping');
        Route::delete('/hosts/{host}', [SnmpMonitoringController::class, 'destroyHost'])->name('hosts.destroy');
        Route::get('/mibs',         [SnmpMonitoringController::class, 'mibs'])->name('mibs');
        Route::post('/mibs',        [SnmpMonitoringController::class, 'storeMib'])->name('mibs.store');
        Route::get('/mibs/{mib}',   [SnmpMonitoringController::class, 'viewMib'])->name('mibs.view');
        Route::post('/hosts/{host}/mib-assign', [SnmpMonitoringController::class, 'updateMibAssignment'])->name('hosts.mib-assign');
        Route::post('/hosts/{host}/force-poll', [SnmpMonitoringController::class, 'forcePoll'])->name('hosts.force-poll');
        Route::post('/hosts/{host}/mib-sensors', [SnmpMonitoringController::class, 'storeMibSensors'])->name('hosts.mib-sensors.store');
        Route::get('/hosts/{host}/metrics', [SnmpMonitoringController::class, 'metrics'])->name('hosts.metrics');
        Route::get('/health', [SnmpMonitoringController::class, 'snmpHealth'])->name('health');
    });

    // ─── Workers Dashboard ─────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/workers')->name('network.workers.')->group(function () {
        Route::get('/',                                   [WorkersDashboardController::class, 'index'])               ->name('index');
        Route::post('/run-ping-all',                      [WorkersDashboardController::class, 'runPingAll'])           ->name('run-ping');
        Route::post('/run-snmp-all',                      [WorkersDashboardController::class, 'runSnmpAll'])           ->name('run-snmp');
        Route::post('/discover-host/{host}',              [WorkersDashboardController::class, 'runDiscoverHost'])      ->name('discover-host');
        Route::post('/discover-interfaces/{host}',        [WorkersDashboardController::class, 'runDiscoverInterfaces'])->name('discover-interfaces');
        Route::post('/clear-failed',                      [WorkersDashboardController::class, 'clearFailedJobs'])      ->name('clear-failed');
    });

    // ─── IP Scanner ───────────────────────────────────────────────
    Route::middleware(['auth', 'permission:manage-network-settings'])->prefix('network/scanner')->name('network.scanner.')->group(function () {
        Route::get('/',       [IpScannerController::class, 'index'])->name('index');
        Route::post('/scan',  [IpScannerController::class, 'scan']) ->name('scan');
    });

    // ─── ISP Connections ────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/isp')->name('network.isp.')->group(function () {
        Route::get('/',            [IspConnectionController::class, 'index'])->name('index');
        Route::get('/create',      [IspConnectionController::class, 'create'])->name('create');
        Route::get('/{isp}/edit',  [IspConnectionController::class, 'edit'])->name('edit');
    });
    Route::middleware('permission:manage-network-settings')->prefix('network/isp')->name('network.isp.')->group(function () {
        Route::post('/',           [IspConnectionController::class, 'store'])->name('store');
        Route::put('/{isp}',      [IspConnectionController::class, 'update'])->name('update');
        Route::delete('/{isp}',   [IspConnectionController::class, 'destroy'])->name('destroy');
    });

    // ─── IP Reservations (IPAM) ─────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/ip-reservations')->name('network.ip-reservations.')->group(function () {
        Route::get('/',                    [IpReservationController::class, 'index'])->name('index');
        Route::get('/create',              [IpReservationController::class, 'create'])->name('create');
        Route::get('/{reservation}/edit',  [IpReservationController::class, 'edit'])->name('edit');
        Route::get('/ajax/get-available-ip', [IpReservationController::class, 'getAvailableIp'])->name('get-available-ip');
    });
    Route::middleware('permission:manage-network-settings')->prefix('network/ip-reservations')->name('network.ip-reservations.')->group(function () {
        Route::post('/',                   [IpReservationController::class, 'store'])->name('store');
        Route::put('/{reservation}',       [IpReservationController::class, 'update'])->name('update');
        Route::delete('/{reservation}',    [IpReservationController::class, 'destroy'])->name('destroy');
    });

    // ─── Landlines ──────────────────────────────────────────────
    Route::middleware('permission:view-extensions')->prefix('telecom/landlines')->name('telecom.landlines.')->group(function () {
        Route::get('/',              [LandlineController::class, 'index'])->name('index');
        Route::get('/create',        [LandlineController::class, 'create'])->name('create');
        Route::get('/{landline}/edit', [LandlineController::class, 'edit'])->name('edit');
    });
    Route::middleware('permission:manage-extensions')->prefix('telecom/landlines')->name('telecom.landlines.')->group(function () {
        Route::post('/',               [LandlineController::class, 'store'])->name('store');
        Route::put('/{landline}',      [LandlineController::class, 'update'])->name('update');
        Route::delete('/{landline}',   [LandlineController::class, 'destroy'])->name('destroy');
    });

    // ─── SLA Dashboard ─────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/sla')->name('network.sla.')->group(function () {
        Route::get('/',       [SlaController::class, 'index'])->name('index');
        Route::get('/{isp}',  [SlaController::class, 'detail'])->name('detail');
    });

    // ─── Port Map ──────────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/port-map')->name('network.port-map.')->group(function () {
        Route::get('/', [PortMapController::class, 'index'])->name('index');
    });

    // ─── Network Topology ──────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/topology')->name('network.topology.')->group(function () {
        Route::get('/',     [TopologyController::class, 'index'])->name('index');
        Route::get('/data', [TopologyController::class, 'data'])->name('data');
    });

    // ─── DHCP Leases ────────────────────────────────────────────
    Route::middleware('permission:view-dhcp-leases')->prefix('network/dhcp')->name('network.dhcp.')->group(function () {
        Route::get('/',        [DhcpLeaseController::class, 'index'])->name('index');
        Route::get('/widget',  [DhcpLeaseController::class, 'widget'])->name('widget');
        Route::get('/{lease}', [DhcpLeaseController::class, 'show'])->name('show');
    });

    // ─── IPAM Subnets ───────────────────────────────────────────
    Route::middleware('permission:view-network')->prefix('network/ipam')->name('network.ipam.')->group(function () {
        Route::get('/',         [IpamController::class, 'index'])->name('index');
        Route::get('/search',   [IpamController::class, 'search'])->name('search');
        Route::post('/',        [IpamController::class, 'store'])->name('store')->middleware('permission:manage-network-settings');
        Route::get('/{subnet}', [IpamController::class, 'show'])->name('show');
    });

    // ─── Sophos Firewalls ───────────────────────────────────────
    Route::prefix('network/sophos')->name('network.sophos.')->group(function () {
        Route::get('/',              [SophosFirewallController::class, 'index'])->name('index')->middleware('permission:view-sophos');
        Route::get('/create',        [SophosFirewallController::class, 'create'])->name('create')->middleware('permission:manage-sophos');
        Route::post('/',             [SophosFirewallController::class, 'store'])->name('store')->middleware('permission:manage-sophos');
        Route::get('/{firewall}',    [SophosFirewallController::class, 'show'])->name('show')->middleware('permission:view-sophos');
        Route::get('/{firewall}/edit', [SophosFirewallController::class, 'edit'])->name('edit')->middleware('permission:manage-sophos');
        Route::put('/{firewall}',    [SophosFirewallController::class, 'update'])->name('update')->middleware('permission:manage-sophos');
        Route::delete('/{firewall}', [SophosFirewallController::class, 'destroy'])->name('destroy')->middleware('permission:manage-sophos');
        Route::post('/{firewall}/sync', [SophosFirewallController::class, 'sync'])->name('sync')->middleware('permission:manage-sophos');
        Route::post('/{firewall}/test', [SophosFirewallController::class, 'testConnection'])->name('test')->middleware('permission:manage-sophos');
    });

    // ─── Notifications (all authenticated users) ──────────────
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',           [NotificationController::class, 'index'])          ->name('index');
        Route::get('settings',    [NotificationController::class, 'settings'])       ->name('settings');
        Route::get('unread-count',[NotificationController::class, 'unreadCount'])    ->name('unread-count');
        Route::patch('{id}/read', [NotificationController::class, 'markRead'])       ->name('read');
        Route::post('read-all',   [NotificationController::class, 'markAllRead'])    ->name('read-all');
        Route::put('settings',    [NotificationController::class, 'updateSettings']) ->name('settings.update');
    });

    // ─── NOC Dashboard ────────────────────────────────────────
    Route::middleware('permission:view-noc')->prefix('noc')->name('noc.')->group(function () {
        Route::get('/',           [NocController::class, 'dashboard']) ->name('dashboard');
        Route::get('/branch/{id}',[NocController::class, 'branch'])    ->name('branch');
        Route::get('/events',     [NocController::class, 'events'])    ->name('events');
        Route::get('/alerts',     [\App\Http\Controllers\Admin\AlertFeedController::class, 'index'])->name('alerts');
        Route::get('/alerts/{id}/timeline', [\App\Http\Controllers\Admin\AlertFeedController::class, 'timeline'])->name('alerts.timeline');
    });
    Route::middleware('permission:manage-noc')->prefix('noc')->name('noc.')->group(function () {
        Route::post('/events/{id}/acknowledge', [NocController::class, 'acknowledge']) ->name('events.acknowledge');
        Route::post('/events/{id}/resolve',     [NocController::class, 'resolve'])     ->name('events.resolve');
    });

    // ─── Incidents ──────────────────────────────────────────────
    Route::middleware('permission:view-incidents')->prefix('noc/incidents')->name('noc.incidents.')->group(function () {
        Route::get('/',                [\App\Http\Controllers\Admin\IncidentController::class, 'index'])->name('index');
        Route::get('/{incident}',      [\App\Http\Controllers\Admin\IncidentController::class, 'show'])->name('show');
    });
    Route::middleware('permission:manage-incidents')->prefix('noc/incidents')->name('noc.incidents.')->group(function () {
        Route::get('/create',          [\App\Http\Controllers\Admin\IncidentController::class, 'create'])->name('create');
        Route::post('/',               [\App\Http\Controllers\Admin\IncidentController::class, 'store'])->name('store');
        Route::get('/{incident}/edit', [\App\Http\Controllers\Admin\IncidentController::class, 'edit'])->name('edit');
        Route::put('/{incident}',      [\App\Http\Controllers\Admin\IncidentController::class, 'update'])->name('update');
        Route::post('/{incident}/comment', [\App\Http\Controllers\Admin\IncidentController::class, 'addComment'])->name('comment');
        Route::get('/from-event/{eventId}', [\App\Http\Controllers\Admin\IncidentController::class, 'createFromEvent'])->name('from-event');
    });

    // ─── Workflows ────────────────────────────────────────────
    Route::middleware('permission:view-workflows')->group(function () {
        Route::get('workflows',              [WorkflowController::class, 'index'])      ->name('workflows.index');
        Route::get('workflows/my-requests',  [WorkflowController::class, 'myRequests']) ->name('workflows.my-requests');
    });
    Route::middleware('permission:manage-workflows')->group(function () {
        Route::get('workflows/create',         [WorkflowController::class, 'create'])      ->name('workflows.create');
        Route::get('workflows/preview-user',   [WorkflowController::class, 'previewUser']) ->name('workflows.preview-user');
        Route::post('workflows',               [WorkflowController::class, 'store'])       ->name('workflows.store');
        Route::post('workflows/{workflow}/cancel', [WorkflowController::class, 'cancel'])  ->name('workflows.cancel');
    });
    Route::middleware('permission:approve-workflows')->group(function () {
        Route::get('workflows/pending',      [WorkflowController::class, 'pending'])    ->name('workflows.pending');
        Route::post('workflows/{workflow}/approve', [WorkflowController::class, 'approve']) ->name('workflows.approve');
        Route::post('workflows/{workflow}/reject',  [WorkflowController::class, 'reject'])  ->name('workflows.reject');
        Route::post('workflows/{workflow}/retry',   [WorkflowController::class, 'retry'])   ->name('workflows.retry');
    });
    Route::middleware('permission:view-workflows')->group(function () {
        Route::get('workflows/{workflow}',   [WorkflowController::class, 'show'])       ->name('workflows.show');
    });

    // ─── Employees ────────────────────────────────────────────
    Route::middleware('permission:view-employees')->group(function () {
        Route::get('employees',              [EmployeeController::class, 'index'])      ->name('employees.index');
    });
    Route::middleware('permission:manage-employees')->group(function () {
        // Static routes MUST come before {employee} wildcard
        Route::get('employees/create',       [EmployeeController::class, 'create'])     ->name('employees.create');
        Route::get('employees/sync',         [EmployeeController::class, 'showSync'])   ->name('employees.sync');
        Route::post('employees/sync',        [EmployeeController::class, 'doSync'])     ->name('employees.sync.do');
        Route::post('employees',             [EmployeeController::class, 'store'])      ->name('employees.store');
    });
    Route::middleware('permission:view-employees')->group(function () {
        Route::get('employees/{employee}',   [EmployeeController::class, 'show'])       ->name('employees.show');
    });
    Route::middleware('permission:manage-employees')->group(function () {
        Route::get('employees/{employee}/edit',            [EmployeeController::class, 'edit'])         ->name('employees.edit');
        Route::put('employees/{employee}',                 [EmployeeController::class, 'update'])        ->name('employees.update');
        Route::post('employees/{employee}/assets',         [EmployeeController::class, 'assignAsset'])   ->name('employees.assets.assign');
        Route::patch('employees/{employee}/assets/{asset}/return', [EmployeeController::class, 'returnAsset']) ->name('employees.assets.return');
        // Employee items (standalone equipment)
        Route::post('employees/{employee}/items',                     [EmployeeItemController::class, 'store'])      ->name('employees.items.store');
        Route::patch('employees/{employee}/items/{item}/return',      [EmployeeItemController::class, 'returnItem']) ->name('employees.items.return');
        Route::delete('employees/{employee}/items/{item}',            [EmployeeItemController::class, 'destroy'])    ->name('employees.items.destroy');
    });

    // ─── Printer Maintenance (nested under printers) ──────────
    Route::middleware('permission:view-printers')->group(function () {
        Route::get('printers/{printer}/maintenance',
            [PrinterMaintenanceController::class, 'index'])   ->name('printers.maintenance.index');
    });
    Route::middleware('permission:manage-printers')->group(function () {
        Route::post('printers/{printer}/maintenance',
            [PrinterMaintenanceController::class, 'store'])   ->name('printers.maintenance.store');
        Route::delete('printers/{printer}/maintenance/{log}',
            [PrinterMaintenanceController::class, 'destroy']) ->name('printers.maintenance.destroy');
    });

    // ── Workflow Templates ────────────────────────────────────────
    Route::get('/workflow-templates', [WorkflowTemplateController::class, 'index'])->name('workflow-templates.index');
    Route::post('/workflow-templates', [WorkflowTemplateController::class, 'store'])->name('workflow-templates.store');
    Route::put('/workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'update'])->name('workflow-templates.update');
    Route::delete('/workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'destroy'])->name('workflow-templates.destroy');

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

    // ── Provisioning Settings ─────────────────────────────────────
    Route::post('/settings/provisioning', [SettingsController::class, 'updateProvisioning'])->name('settings.provisioning');
    Route::get('/settings/provisioning-licenses',  [SettingsController::class, 'provisioningLicenses'])->name('settings.provisioning-licenses');
    Route::post('/settings/provisioning-licenses', [SettingsController::class, 'setDefaultLicense'])   ->name('settings.provisioning-licenses.save');

    // ─── ITAM ─────────────────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam')->name('itam.')->group(function () {
        Route::get('/',         [ItamController::class, 'dashboard']) ->name('dashboard');
    });

    // ─── Suppliers ────────────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam/suppliers')->name('itam.suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-itam')->prefix('itam/suppliers')->name('itam.suppliers.')->group(function () {
        Route::post('/',              [SupplierController::class, 'store'])   ->name('store');
        Route::put('/{supplier}',     [SupplierController::class, 'update'])  ->name('update');
        Route::delete('/{supplier}',  [SupplierController::class, 'destroy']) ->name('destroy');
    });

    // ─── Software Licenses ────────────────────────────────────────
    Route::middleware('permission:view-licenses')->prefix('itam/licenses')->name('itam.licenses.')->group(function () {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-licenses')->prefix('itam/licenses')->name('itam.licenses.')->group(function () {
        Route::post('/',                          [LicenseController::class, 'store'])    ->name('store');
        Route::put('/{license}',                  [LicenseController::class, 'update'])   ->name('update');
        Route::delete('/{license}',               [LicenseController::class, 'destroy'])  ->name('destroy');
        Route::post('/{license}/assign',          [LicenseController::class, 'assign'])   ->name('assign');
        Route::delete('/{license}/unassign/{assignment}', [LicenseController::class, 'unassign'])->name('unassign');
    });

    // ─── Accessories ──────────────────────────────────────────────
    Route::middleware('permission:view-accessories')->prefix('itam/accessories')->name('itam.accessories.')->group(function () {
        Route::get('/', [AccessoryController::class, 'index'])->name('index');
    });
    Route::middleware('permission:manage-accessories')->prefix('itam/accessories')->name('itam.accessories.')->group(function () {
        Route::post('/',                              [AccessoryController::class, 'store'])   ->name('store');
        Route::put('/{accessory}',                    [AccessoryController::class, 'update'])  ->name('update');
        Route::delete('/{accessory}',                 [AccessoryController::class, 'destroy']) ->name('destroy');
        Route::post('/{accessory}/assign',            [AccessoryController::class, 'assign'])  ->name('assign');
        Route::patch('/{accessory}/assignments/{assignment}/return', [AccessoryController::class, 'returnItem'])->name('return');
    });

    // ─── Azure Device Sync ────────────────────────────────────────
    Route::middleware('permission:view-itam')->prefix('itam/azure')->name('itam.azure.')->group(function () {
        Route::get('/',                  [AzureSyncController::class, 'index'])->name('index');
        Route::get('/{azureDevice}',     [AzureSyncController::class, 'show']) ->name('show');
    });
    Route::middleware('permission:manage-itam')->prefix('itam/azure')->name('itam.azure.')->group(function () {
        Route::post('/sync',                       [AzureSyncController::class, 'sync'])        ->name('sync');
        Route::patch('/{azureDevice}/approve',     [AzureSyncController::class, 'approve'])     ->name('approve');
        Route::patch('/{azureDevice}/reject',      [AzureSyncController::class, 'reject'])      ->name('reject');
        Route::post('/{azureDevice}/link-device',  [AzureSyncController::class, 'linkDevice'])  ->name('link-device');
        Route::get('/{azureDevice}/create-device', [AzureSyncController::class, 'createDevice'])->name('create-device');
        Route::get('/{azureDevice}/preview-import', [AzureSyncController::class, 'previewImport'])->name('preview-import');
        Route::post('/{azureDevice}/import',       [AzureSyncController::class, 'importToItam'])->name('import');
    });


});

/*
|--------------------------------------------------------------------------
| Auth Routes (Laravel Breeze)
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
