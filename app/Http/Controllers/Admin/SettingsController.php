<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Services\AvePoint\AvePointApiService;
use App\Services\Backup\SftpgoApiService;
use App\Services\Identity\GraphService;
use App\Services\SmtpConfigService;
use App\Services\Sophos\SophosCentralService;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Show settings form
     */
    public function index()
    {
        $settings = Setting::get();
        $ucmServers = UcmServer::orderBy('name')->get();

        return view('admin.settings', compact('settings', 'ucmServers'));
    }

    /**
     * Update general settings
     */
    public function update(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'metrics_retention_days' => 'nullable|integer|min:1|max:3650',
            'vq_retention_days' => 'nullable|integer|min:1|max:3650',
            'switch_drop_retention_days' => 'nullable|integer|min:1|max:3650',
            'workflow_retention_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $settings = Setting::get();
        $old = [
            'company_name' => $settings->company_name,
            'metrics_retention_days' => $settings->metrics_retention_days,
        ];

        $settings->company_name = $request->company_name;
        $settings->metrics_retention_days = (int) ($request->metrics_retention_days ?: 90);
        $settings->vq_retention_days = (int) ($request->vq_retention_days ?: 90);
        $settings->switch_drop_retention_days = (int) ($request->switch_drop_retention_days ?: 30);
        $settings->workflow_retention_days = (int) ($request->workflow_retention_days ?: 365);

        // Handle logo upload
        if ($request->hasFile('company_logo')) {
            if ($settings->company_logo && Storage::disk('public')->exists($settings->company_logo)) {
                Storage::disk('public')->delete($settings->company_logo);
            }
            $path = $request->file('company_logo')->store('logos', 'public');
            $settings->company_logo = $path;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'updated',
            'changes' => [
                'old' => $old,
                'new' => [
                    'company_name' => $settings->company_name,
                    'metrics_retention_days' => $settings->metrics_retention_days,
                    'logo_changed' => $request->hasFile('company_logo'),
                ],
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Settings updated successfully.');
    }

    /**
     * Delete logo
     */
    public function deleteLogo()
    {
        $settings = Setting::get();

        if ($settings->company_logo && Storage::disk('public')->exists($settings->company_logo)) {
            Storage::disk('public')->delete($settings->company_logo);
        }

        $settings->company_logo = null;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'deleted',
            'changes' => ['company_logo' => 'removed'],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Logo deleted successfully.');
    }

    /**
     * Update Microsoft SSO settings
     */
    public function updateSso(Request $request)
    {
        $request->validate([
            'sso_tenant_id' => 'nullable|string|max:100',
            'sso_client_id' => 'nullable|string|max:100',
            'sso_client_secret' => 'nullable|string|max:500',
            'sso_default_role' => 'required|in:super_admin,admin,hr,viewer,browser_user',
        ]);

        $settings = Setting::get();
        $before = [
            'sso_enabled' => (bool) $settings->sso_enabled,
            'sso_tenant_id' => $settings->sso_tenant_id,
            'sso_client_id' => $settings->sso_client_id,
            'sso_default_role' => $settings->sso_default_role,
        ];
        $settings->sso_enabled = $request->boolean('sso_enabled');
        $settings->sso_tenant_id = $request->sso_tenant_id;
        $settings->sso_client_id = $request->sso_client_id;
        $settings->sso_default_role = $request->sso_default_role;

        if ($request->filled('sso_client_secret')) {
            $settings->sso_client_secret = $request->sso_client_secret;
        }

        $settings->save();

        $after = [
            'sso_enabled' => (bool) $settings->sso_enabled,
            'sso_tenant_id' => $settings->sso_tenant_id,
            'sso_client_id' => $settings->sso_client_id,
            'sso_default_role' => $settings->sso_default_role,
        ];

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'sso_updated',
            'changes' => [
                'before' => $before,
                'after' => $after,
                'secret_changed' => $request->filled('sso_client_secret'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'SSO settings updated.');
    }

    // ─────────────────────────────────────────────────────────────
    // Meraki Network Settings
    // ─────────────────────────────────────────────────────────────

    /**
     * Update Meraki API configuration
     */
    public function updateMeraki(Request $request)
    {
        $request->validate([
            'meraki_org_id' => 'nullable|string|max:100',
            'meraki_api_key' => 'nullable|string|max:500',
            'meraki_polling_interval' => 'required|integer|min:5|max:1440',
        ]);

        $settings = Setting::get();
        $before = [
            'meraki_enabled' => (bool) $settings->meraki_enabled,
            'meraki_org_id' => $settings->meraki_org_id,
            'meraki_polling_interval' => $settings->meraki_polling_interval,
        ];
        $settings->meraki_enabled = $request->boolean('meraki_enabled');
        $settings->meraki_org_id = $request->meraki_org_id;
        $settings->meraki_polling_interval = (int) $request->meraki_polling_interval;

        if ($request->filled('meraki_api_key')) {
            $settings->meraki_api_key = $request->meraki_api_key;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'meraki_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'meraki_enabled' => (bool) $settings->meraki_enabled,
                    'meraki_org_id' => $settings->meraki_org_id,
                    'meraki_polling_interval' => $settings->meraki_polling_interval,
                ],
                'api_key_changed' => $request->filled('meraki_api_key'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Meraki settings updated.');
    }

    /**
     * Update Microsoft Graph / Identity settings
     */
    public function updateGraph(Request $request)
    {
        $request->validate([
            'graph_tenant_id' => 'nullable|string|max:100',
            'graph_client_id' => 'nullable|string|max:100',
            'graph_client_secret' => 'nullable|string|max:500',
            'graph_default_password' => 'nullable|string|max:255',
            'graph_default_license_sku' => 'nullable|string|max:100',
            'identity_sync_interval' => 'required|integer|min:5|max:1440',
            'intune_net_data_script_id' => 'nullable|string|max:100',
        ]);

        $settings = Setting::get();
        $before = [
            'identity_sync_enabled' => (bool) $settings->identity_sync_enabled,
            'graph_tenant_id' => $settings->graph_tenant_id,
            'graph_client_id' => $settings->graph_client_id,
            'identity_sync_interval' => $settings->identity_sync_interval,
            'intune_net_data_script_id' => $settings->intune_net_data_script_id,
        ];
        $settings->identity_sync_enabled = $request->boolean('identity_sync_enabled');
        $settings->graph_tenant_id = $request->graph_tenant_id;
        $settings->graph_client_id = $request->graph_client_id;
        $settings->graph_default_password = $request->graph_default_password;
        $settings->graph_default_license_sku = $request->graph_default_license_sku;
        $settings->identity_sync_interval = (int) $request->identity_sync_interval;
        $settings->intune_net_data_script_id = $request->intune_net_data_script_id;

        if ($request->filled('graph_client_secret')) {
            $settings->graph_client_secret = $request->graph_client_secret;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'graph_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'identity_sync_enabled' => (bool) $settings->identity_sync_enabled,
                    'graph_tenant_id' => $settings->graph_tenant_id,
                    'graph_client_id' => $settings->graph_client_id,
                    'identity_sync_interval' => $settings->identity_sync_interval,
                    'intune_net_data_script_id' => $settings->intune_net_data_script_id,
                ],
                'secret_changed' => $request->filled('graph_client_secret'),
                'default_password_changed' => $request->filled('graph_default_password'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Identity (Graph) settings updated.');
    }

    // ─────────────────────────────────────────────────────────────
    // GDMS API Settings
    // ─────────────────────────────────────────────────────────────

    public function updateGdms(Request $request)
    {
        $request->validate([
            'gdms_base_url' => 'nullable|string|max:255',
            'gdms_client_id' => 'nullable|string|max:100',
            'gdms_client_secret' => 'nullable|string|max:500',
            'gdms_org_id' => 'nullable|string|max:100',
            'gdms_username' => 'nullable|string|max:100',
            'gdms_password_hash' => 'nullable|string|max:255',
        ]);

        $settings = Setting::get();
        $before = [
            'gdms_base_url' => $settings->gdms_base_url,
            'gdms_client_id' => $settings->gdms_client_id,
            'gdms_org_id' => $settings->gdms_org_id,
            'gdms_username' => $settings->gdms_username,
        ];
        $settings->gdms_base_url = $request->gdms_base_url ?: 'https://www.gdms.cloud/oapi';
        $settings->gdms_client_id = $request->gdms_client_id;
        $settings->gdms_org_id = $request->gdms_org_id;
        $settings->gdms_username = $request->gdms_username;
        $settings->gdms_password_hash = $request->gdms_password_hash;

        if ($request->filled('gdms_client_secret')) {
            $settings->gdms_client_secret = $request->gdms_client_secret;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'gdms_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'gdms_base_url' => $settings->gdms_base_url,
                    'gdms_client_id' => $settings->gdms_client_id,
                    'gdms_org_id' => $settings->gdms_org_id,
                    'gdms_username' => $settings->gdms_username,
                ],
                'secret_changed' => $request->filled('gdms_client_secret'),
                'password_hash_changed' => $request->filled('gdms_password_hash'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'GDMS API settings updated.')
            ->withFragment('gdms');
    }

    // ─────────────────────────────────────────────────────────────
    // Sophos Central (cloud API — APs, firewall fleet, alerts)
    // ─────────────────────────────────────────────────────────────

    public function updateSophosCentral(Request $request)
    {
        $request->validate([
            'sophos_central_client_id' => 'nullable|string|max:255',
            'sophos_central_client_secret' => 'nullable|string|max:500',
            'sophos_central_sync_interval' => 'nullable|integer|min:5|max:1440',
        ]);

        $settings = Setting::get();
        $before = [
            'sophos_central_enabled' => (bool) $settings->sophos_central_enabled,
            'sophos_central_client_id' => $settings->sophos_central_client_id,
            'sophos_central_sync_interval' => $settings->sophos_central_sync_interval,
            'sophos_central_alerts_enabled' => (bool) $settings->sophos_central_alerts_enabled,
        ];

        $clientIdChanged = $request->sophos_central_client_id !== $settings->sophos_central_client_id;

        $settings->sophos_central_enabled = $request->boolean('sophos_central_enabled');
        $settings->sophos_central_alerts_enabled = $request->boolean('sophos_central_alerts_enabled');
        $settings->sophos_central_client_id = $request->sophos_central_client_id;
        $settings->sophos_central_sync_interval = (int) ($request->sophos_central_sync_interval ?: 15);

        if ($request->filled('sophos_central_client_secret')) {
            $settings->sophos_central_client_secret = $request->sophos_central_client_secret;
        }

        // New credentials → stale tenant/data-region cache must be re-discovered
        if ($clientIdChanged || $request->filled('sophos_central_client_secret')) {
            $settings->sophos_central_tenant_id = null;
            $settings->sophos_central_data_region = null;
            \Illuminate\Support\Facades\Cache::forget('sophos_central_access_token');
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'sophos_central_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'sophos_central_enabled' => (bool) $settings->sophos_central_enabled,
                    'sophos_central_client_id' => $settings->sophos_central_client_id,
                    'sophos_central_sync_interval' => $settings->sophos_central_sync_interval,
                    'sophos_central_alerts_enabled' => (bool) $settings->sophos_central_alerts_enabled,
                ],
                'secret_changed' => $request->filled('sophos_central_client_secret'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Sophos Central settings updated.')
            ->withFragment('sophos-central');
    }

    public function testSophosCentral()
    {
        try {
            return response()->json((new SophosCentralService)->testConnection());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'detail' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Teamtailor / Recruitment API Settings
    // ─────────────────────────────────────────────────────────────

    public function updateTeamtailor(Request $request)
    {
        $request->validate([
            'teamtailor_base_url' => 'nullable|url|max:255',
            'teamtailor_api_version' => 'nullable|string|max:20',
            'teamtailor_api_key' => 'nullable|string|max:500',
        ]);

        $settings = Setting::get();
        $before = [
            'teamtailor_base_url' => $settings->teamtailor_base_url,
            'teamtailor_api_version' => $settings->teamtailor_api_version,
        ];
        $settings->teamtailor_base_url = TeamtailorApiService::normalizeBaseUrl($request->teamtailor_base_url ?: 'https://api.teamtailor.com');
        $settings->teamtailor_api_version = $request->teamtailor_api_version ?: '20240904';

        if ($request->filled('teamtailor_api_key')) {
            $settings->teamtailor_api_key = $request->teamtailor_api_key;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'teamtailor_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'teamtailor_base_url' => $settings->teamtailor_base_url,
                    'teamtailor_api_version' => $settings->teamtailor_api_version,
                ],
                'key_changed' => $request->filled('teamtailor_api_key'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Teamtailor API settings updated.')
            ->withFragment('teamtailor');
    }

    // ─────────────────────────────────────────────────────────────
    // SMTP / Outgoing Mail Settings
    // ─────────────────────────────────────────────────────────────

    public function updateSmtp(Request $request)
    {
        $request->validate([
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:500',
            'smtp_from_address' => 'nullable|email|max:255',
            'smtp_from_name' => 'nullable|string|max:255',
            'snmp_alert_email' => 'nullable|email|max:255',
        ]);

        $settings = Setting::get();
        $before = [
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_encryption' => $settings->smtp_encryption,
            'smtp_username' => $settings->smtp_username,
            'smtp_from_address' => $settings->smtp_from_address,
            'smtp_from_name' => $settings->smtp_from_name,
            'snmp_alert_email' => $settings->snmp_alert_email,
        ];
        $settings->smtp_host = $request->smtp_host;
        $settings->smtp_port = $request->smtp_port ?: 587;
        $settings->smtp_encryption = $request->smtp_encryption ?: 'tls';
        $settings->smtp_username = $request->smtp_username;
        $settings->smtp_from_address = $request->smtp_from_address;
        $settings->smtp_from_name = $request->smtp_from_name;
        $settings->snmp_alert_email = $request->snmp_alert_email;

        if ($request->filled('smtp_password')) {
            $settings->smtp_password = $request->smtp_password;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'smtp_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'smtp_host' => $settings->smtp_host,
                    'smtp_port' => $settings->smtp_port,
                    'smtp_encryption' => $settings->smtp_encryption,
                    'smtp_username' => $settings->smtp_username,
                    'smtp_from_address' => $settings->smtp_from_address,
                    'smtp_from_name' => $settings->smtp_from_name,
                    'snmp_alert_email' => $settings->snmp_alert_email,
                ],
                'password_changed' => $request->filled('smtp_password'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'SMTP settings updated.')
            ->withFragment('smtp');
    }

    public function testSmtp(Request $request)
    {
        $request->validate(['to' => 'required|email']);

        try {
            (new SmtpConfigService)->sendTestEmail($request->to);

            return response()->json([
                'success' => true,
                'message' => "Test email sent to {$request->to}.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Locations (Settings → Locations)
    // ─────────────────────────────────────────────────────────────

    public function locations()
    {
        $branches = Branch::with([
            'networkFloors.racks',
            'networkFloors.offices',
        ])->orderBy('name')->get();

        return view('admin.settings.locations', compact('branches'));
    }

    // ─────────────────────────────────────────────────────────────
    // Departments (Settings → Departments)
    // ─────────────────────────────────────────────────────────────

    public function departments()
    {
        $departments = Department::orderBy('sort_order')->orderBy('name')->get();

        return view('admin.settings.departments', compact('departments'));
    }

    public function storeDepartment(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:departments,name',
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $dept = Department::create($data);

        ActivityLog::create([
            'model_type' => Department::class,
            'model_id' => $dept->id,
            'action' => 'created',
            'changes' => $data,
            'user_id' => Auth::id(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['id' => $dept->id, 'name' => $dept->name], 201);
        }

        return back()->with('success', "Department \"{$data['name']}\" created.");
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:departments,name,'.$department->id,
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $before = $department->only(['name', 'description', 'sort_order']);
        $department->update($data);

        ActivityLog::create([
            'model_type' => Department::class,
            'model_id' => $department->id,
            'action' => 'updated',
            'changes' => ['before' => $before, 'after' => $department->only(['name', 'description', 'sort_order'])],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Department \"{$department->name}\" updated.");
    }

    public function destroyDepartment(Department $department)
    {
        $name = $department->name;
        $id = $department->id;
        $department->delete();

        ActivityLog::create([
            'model_type' => Department::class,
            'model_id' => $id,
            'action' => 'deleted',
            'changes' => ['name' => $name],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Department \"{$name}\" deleted.");
    }

    // ─────────────────────────────────────────────────────────────
    // Provisioning Settings
    // ─────────────────────────────────────────────────────────────

    public function updateProvisioning(Request $request)
    {
        $request->validate([
            'upn_domain' => 'nullable|string|max:100',
            'default_ucm_id' => 'nullable|exists:ucm_servers,id',
            'ext_range_start' => 'nullable|integer|min:1',
            'ext_range_end' => 'nullable|integer|min:1',
            'ext_default_secret' => 'nullable|string|max:100',
            'ext_default_permission' => 'nullable|in:internal,local,national,international',
            'profile_office_template' => 'nullable|string|max:255',
            'profile_phone_template' => 'nullable|string|max:255',
        ]);

        $settings = Setting::get();
        $settings->upn_domain = $request->upn_domain;
        $settings->default_ucm_id = $request->default_ucm_id ?: null;
        $settings->ext_range_start = $request->ext_range_start ?: 1000;
        $settings->ext_range_end = $request->ext_range_end ?: 1999;
        $settings->ext_default_secret = $request->ext_default_secret;
        $settings->ext_default_permission = $request->ext_default_permission ?: 'local';
        $settings->profile_office_template = $request->profile_office_template;
        $settings->profile_phone_template = $request->profile_phone_template;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'updated',
            'changes' => ['section' => 'provisioning', 'upn_domain' => $request->upn_domain],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Provisioning settings updated.')
            ->withFragment('provisioning');
    }

    // ─────────────────────────────────────────────────────────────
    // CUPS Print Manager Settings
    // ─────────────────────────────────────────────────────────────

    public function updateCups(Request $request)
    {
        $request->validate([
            'cups_enabled' => 'boolean',
            'cups_ipp_domain' => 'nullable|string|max:255',
            'cups_refresh_interval' => 'required|integer|min:1|max:1440',
        ]);

        $settings = Setting::get();
        $settings->cups_enabled = $request->boolean('cups_enabled');
        $settings->cups_ipp_domain = $request->cups_ipp_domain;
        $settings->cups_refresh_interval = (int) $request->cups_refresh_interval;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'updated',
            'changes' => [
                'section' => 'cups',
                'cups_enabled' => $settings->cups_enabled,
                'cups_ipp_domain' => $settings->cups_ipp_domain,
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'CUPS Print Manager settings updated.')
            ->withFragment('cups');
    }

    // ─────────────────────────────────────────────────────────────
    // Provisioning Licenses — list Azure SKUs + set default
    // ─────────────────────────────────────────────────────────────

    public function provisioningLicenses()
    {
        $settings = Setting::get();
        $licenses = [];
        $error = null;

        try {
            $graph = new GraphService;
            $licenses = $graph->listSubscribedSkus();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.settings.provisioning-licenses', compact('settings', 'licenses', 'error'));
    }

    public function setDefaultLicense(Request $request)
    {
        $request->validate([
            'license_skus' => 'nullable|array',
            'license_skus.*' => 'string|max:100',
            // fallback for manual single-SKU entry when Azure is unreachable
            'license_sku' => 'nullable|string|max:100',
        ]);

        $settings = Setting::get();

        // Prefer multi-select checkboxes; fall back to single-SKU manual entry
        if ($request->has('license_skus')) {
            $skus = array_values(array_filter((array) $request->input('license_skus', [])));
            $settings->graph_default_license_skus = empty($skus) ? null : $skus;
            // Keep legacy single-sku field pointing at the first selection for backward compat
            $settings->graph_default_license_sku = $skus[0] ?? null;
        } else {
            // Manual single-SKU entry (used when Azure is unreachable)
            $sku = $request->license_sku ?: null;
            $settings->graph_default_license_sku = $sku;
            $settings->graph_default_license_skus = $sku ? [$sku] : null;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'updated',
            'changes' => [
                'section' => 'provisioning_license',
                'skus' => $settings->graph_default_license_skus,
            ],
            'user_id' => Auth::id(),
        ]);

        $count = count($settings->graph_default_license_skus ?? []);

        return back()->with('success', $count > 0
            ? "Default provisioning license(s) updated ({$count} selected)."
            : 'Default provisioning license cleared.');
    }

    // ─────────────────────────────────────────────────────────────
    // Ticketing API (external ticket system for new-employee tickets)
    // ─────────────────────────────────────────────────────────────

    public function updateTicketing(Request $request)
    {
        $request->validate([
            'ticketing_api_url' => 'nullable|url|max:500',
            'ticketing_api_key' => 'nullable|string|max:500',
        ]);

        $settings = Setting::get();
        $before = [
            'ticketing_api_enabled' => (bool) $settings->ticketing_api_enabled,
            'ticketing_api_url' => $settings->ticketing_api_url,
        ];
        $settings->ticketing_api_enabled = $request->boolean('ticketing_api_enabled');
        $settings->ticketing_api_url = $request->ticketing_api_url;

        if ($request->filled('ticketing_api_key')) {
            $settings->ticketing_api_key = $request->ticketing_api_key;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'ticketing_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'ticketing_api_enabled' => (bool) $settings->ticketing_api_enabled,
                    'ticketing_api_url' => $settings->ticketing_api_url,
                ],
                'key_changed' => $request->filled('ticketing_api_key'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Ticketing API settings updated.')
            ->withFragment('ticketing');
    }

    public function updateItam(Request $request)
    {
        $request->validate([
            'itam_asset_prefix' => 'required|string|max:10|regex:/^[A-Z0-9]+$/i',
            'itam_code_padding' => 'required|integer|min:1|max:10',
            'itam_company_url' => 'nullable|url|max:255',
        ]);

        $settings = Setting::get();
        $settings->itam_asset_prefix = strtoupper($request->itam_asset_prefix);
        $settings->itam_code_padding = (int) $request->itam_code_padding;
        $settings->itam_company_url = $request->itam_company_url;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting', 'model_id' => 1,
            'action' => 'updated',
            'changes' => ['section' => 'itam', 'prefix' => $settings->itam_asset_prefix],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'ITAM settings saved.');
    }

    // ─────────────────────────────────────────────────────────────
    // AvePoint Graph API
    // ─────────────────────────────────────────────────────────────

    public function updateAvePoint(Request $request)
    {
        $request->validate([
            'avepoint_base_url' => 'nullable|url|max:255',
            'avepoint_tenant_id' => 'nullable|string|max:100',
            'avepoint_client_id' => 'nullable|string|max:100',
            'avepoint_client_secret' => 'nullable|string|max:500',
            'avepoint_region' => 'nullable|string|max:20',
            'avepoint_location' => 'nullable|string|max:20',
            'avepoint_export_endpoint' => 'nullable|string|max:255',
            'avepoint_download_endpoint' => 'nullable|string|max:255',
        ]);

        $settings = Setting::get();
        $before = [
            'avepoint_enabled' => (bool) $settings->avepoint_enabled,
            'avepoint_base_url' => $settings->avepoint_base_url,
            'avepoint_tenant_id' => $settings->avepoint_tenant_id,
            'avepoint_client_id' => $settings->avepoint_client_id,
            'avepoint_region' => $settings->avepoint_region,
            'avepoint_export_endpoint' => $settings->avepoint_export_endpoint,
            'avepoint_download_endpoint' => $settings->avepoint_download_endpoint,
        ];

        // Strip the well-known placeholder examples — these are illustrative paths
        // I shipped in the form, NOT real AvePoint endpoints, and saving them
        // would cause the backup-request flow to POST to a 404 URL.
        $placeholderExports = ['/backup/m365/exports', 'backup/m365/exports'];
        $placeholderDownloads = ['/backup/m365/exports/{jobId}/file', 'backup/m365/exports/{jobId}/file'];

        $exportEp = trim((string) $request->avepoint_export_endpoint);
        $downloadEp = trim((string) $request->avepoint_download_endpoint);
        if (in_array($exportEp, $placeholderExports, true)) {
            $exportEp = '';
        }
        if (in_array($downloadEp, $placeholderDownloads, true)) {
            $downloadEp = '';
        }

        $settings->avepoint_enabled = $request->boolean('avepoint_enabled');
        $settings->avepoint_base_url = $request->avepoint_base_url;
        $settings->avepoint_tenant_id = $request->avepoint_tenant_id;
        $settings->avepoint_client_id = $request->avepoint_client_id;
        $settings->avepoint_region = $request->avepoint_region;
        $settings->avepoint_location = $request->avepoint_location ?: null;
        $settings->avepoint_export_endpoint = $exportEp ?: null;
        $settings->avepoint_download_endpoint = $downloadEp ?: null;

        if ($request->filled('avepoint_client_secret')) {
            $settings->avepoint_client_secret = $request->avepoint_client_secret;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'avepoint_updated',
            'changes' => [
                'before' => $before,
                'after' => array_merge($before, [
                    'avepoint_enabled' => (bool) $settings->avepoint_enabled,
                    'avepoint_base_url' => $settings->avepoint_base_url,
                    'avepoint_tenant_id' => $settings->avepoint_tenant_id,
                    'avepoint_client_id' => $settings->avepoint_client_id,
                    'avepoint_region' => $settings->avepoint_region,
                    'avepoint_export_endpoint' => $settings->avepoint_export_endpoint,
                    'avepoint_download_endpoint' => $settings->avepoint_download_endpoint,
                ]),
                'secret_changed' => $request->filled('avepoint_client_secret'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'AvePoint settings updated.')
            ->withFragment('avepoint');
    }

    public function testAvePoint()
    {
        try {
            $result = (new AvePointApiService)->testConnection();

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'detail' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SFTPGo (device backup ingestion)
    // ─────────────────────────────────────────────────────────────

    public function updateSftpgo(Request $request)
    {
        $request->validate([
            'sftpgo_base_url' => 'nullable|url|max:255',
            'sftpgo_admin_username' => 'nullable|string|max:150',
            'sftpgo_admin_password' => 'nullable|string|max:255',
            'sftpgo_api_key' => 'nullable|string|max:500',
            'sftpgo_webhook_secret' => 'nullable|string|max:255',
            'sftpgo_default_quota_mb' => 'nullable|integer|min:0',
            'sftpgo_home_root' => 'nullable|string|max:255',
        ]);

        $settings = Setting::get();
        $before = [
            'sftpgo_enabled' => (bool) $settings->sftpgo_enabled,
            'sftpgo_base_url' => $settings->sftpgo_base_url,
            'sftpgo_admin_username' => $settings->sftpgo_admin_username,
            'sftpgo_sftp_enabled' => (bool) $settings->sftpgo_sftp_enabled,
            'sftpgo_ftp_enabled' => (bool) $settings->sftpgo_ftp_enabled,
            'sftpgo_default_quota_mb' => $settings->sftpgo_default_quota_mb,
            'sftpgo_home_root' => $settings->sftpgo_home_root,
        ];

        $settings->sftpgo_enabled = $request->boolean('sftpgo_enabled');
        $settings->sftpgo_base_url = $request->sftpgo_base_url;
        $settings->sftpgo_admin_username = $request->sftpgo_admin_username;
        $settings->sftpgo_sftp_enabled = $request->boolean('sftpgo_sftp_enabled');
        $settings->sftpgo_ftp_enabled = $request->boolean('sftpgo_ftp_enabled');
        $settings->sftpgo_default_quota_mb = $request->filled('sftpgo_default_quota_mb') ? (int) $request->sftpgo_default_quota_mb : null;
        $settings->sftpgo_home_root = $request->sftpgo_home_root ?: '/srv/backups';

        if ($request->filled('sftpgo_admin_password')) {
            $settings->sftpgo_admin_password = $request->sftpgo_admin_password;
        }
        if ($request->filled('sftpgo_api_key')) {
            $settings->sftpgo_api_key = $request->sftpgo_api_key;
        }
        if ($request->filled('sftpgo_webhook_secret')) {
            $settings->sftpgo_webhook_secret = $request->sftpgo_webhook_secret;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'sftpgo_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'sftpgo_enabled' => (bool) $settings->sftpgo_enabled,
                    'sftpgo_base_url' => $settings->sftpgo_base_url,
                    'sftpgo_admin_username' => $settings->sftpgo_admin_username,
                    'sftpgo_sftp_enabled' => (bool) $settings->sftpgo_sftp_enabled,
                    'sftpgo_ftp_enabled' => (bool) $settings->sftpgo_ftp_enabled,
                    'sftpgo_default_quota_mb' => $settings->sftpgo_default_quota_mb,
                    'sftpgo_home_root' => $settings->sftpgo_home_root,
                ],
                'admin_password_changed' => $request->filled('sftpgo_admin_password'),
                'api_key_changed' => $request->filled('sftpgo_api_key'),
                'webhook_secret_changed' => $request->filled('sftpgo_webhook_secret'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'SFTPGo settings updated.')
            ->withFragment('sftpgo');
    }

    public function testSftpgo()
    {
        try {
            return response()->json((new SftpgoApiService)->testConnection());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'detail' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Azure Blob (Backup Archive)
    // ─────────────────────────────────────────────────────────────

    public function updateAzureBlob(Request $request)
    {
        $request->validate([
            'azure_blob_account' => 'nullable|string|max:100',
            'azure_blob_container' => 'nullable|string|max:100',
            'azure_blob_key' => 'nullable|string|max:500',
            'azure_blob_endpoint_suffix' => 'nullable|string|max:100',
        ]);

        $settings = Setting::get();
        $before = [
            'azure_blob_enabled' => (bool) $settings->azure_blob_enabled,
            'azure_blob_account' => $settings->azure_blob_account,
            'azure_blob_container' => $settings->azure_blob_container,
            'azure_blob_endpoint_suffix' => $settings->azure_blob_endpoint_suffix,
        ];

        $settings->azure_blob_enabled = $request->boolean('azure_blob_enabled');
        $settings->azure_blob_account = $request->azure_blob_account;
        $settings->azure_blob_container = $request->azure_blob_container;
        $settings->azure_blob_endpoint_suffix = $request->azure_blob_endpoint_suffix ?: 'core.windows.net';

        if ($request->filled('azure_blob_key')) {
            $settings->azure_blob_key = $request->azure_blob_key;
        }

        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'azure_blob_updated',
            'changes' => [
                'before' => $before,
                'after' => array_merge($before, [
                    'azure_blob_enabled' => (bool) $settings->azure_blob_enabled,
                    'azure_blob_account' => $settings->azure_blob_account,
                    'azure_blob_container' => $settings->azure_blob_container,
                    'azure_blob_endpoint_suffix' => $settings->azure_blob_endpoint_suffix,
                ]),
                'key_changed' => $request->filled('azure_blob_key'),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Azure Blob settings updated.')
            ->withFragment('azure-blob');
    }

    public function testAzureBlob()
    {
        $settings = Setting::get();
        if (! $settings->azure_blob_account || ! $settings->azure_blob_key || ! $settings->azure_blob_container) {
            return response()->json([
                'ok' => false,
                'detail' => 'Storage account, container, and access key are required.',
            ]);
        }

        try {
            // Forget any cached driver so it picks up the latest settings.
            Storage::forgetDisk('azure_offboarding');

            Storage::disk('azure_offboarding')->put('healthcheck.txt', 'ok '.now()->toIso8601String());
            $contents = Storage::disk('azure_offboarding')->get('healthcheck.txt');
            Storage::disk('azure_offboarding')->delete('healthcheck.txt');

            return response()->json([
                'ok' => str_starts_with((string) $contents, 'ok '),
                'detail' => 'Wrote and read healthcheck.txt successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'detail' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Offboarding behavior
    // ─────────────────────────────────────────────────────────────

    public function updateOffboarding(Request $request)
    {
        $request->validate([
            'offboarding_group_id' => 'nullable|string|max:100',
            'offboarding_exchange_only_sku' => 'nullable|string|max:100',
            'offboarding_retention_days' => 'required|integer|min:1|max:365',
            'offboarding_download_expiry_days' => 'required|integer|min:1|max:30',
            'offboarding_manager_grace_days' => 'required|integer|min:0|max:30',
            'offboarding_it_escalation_email' => 'nullable|email|max:200',
        ]);

        $settings = Setting::get();
        $before = $settings->only([
            'offboarding_enabled',
            'offboarding_group_id',
            'offboarding_exchange_only_sku',
            'offboarding_retention_days',
            'offboarding_download_expiry_days',
            'offboarding_manager_grace_days',
            'offboarding_it_escalation_email',
        ]);

        $settings->offboarding_enabled = $request->boolean('offboarding_enabled');
        $settings->offboarding_group_id = $request->offboarding_group_id;
        $settings->offboarding_exchange_only_sku = $request->offboarding_exchange_only_sku;
        $settings->offboarding_retention_days = (int) $request->offboarding_retention_days;
        $settings->offboarding_download_expiry_days = (int) $request->offboarding_download_expiry_days;
        $settings->offboarding_manager_grace_days = (int) $request->offboarding_manager_grace_days;
        $settings->offboarding_it_escalation_email = $request->offboarding_it_escalation_email;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'offboarding_updated',
            'changes' => [
                'before' => $before,
                'after' => $settings->only(array_keys($before)),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Offboarding settings updated.')
            ->withFragment('offboarding');
    }
}
