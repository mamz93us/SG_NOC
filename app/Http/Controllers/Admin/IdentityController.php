<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncIdentityData;
use App\Models\ActivityLog;
use App\Models\IdentityGroup;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

class IdentityController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Users
    // ─────────────────────────────────────────────────────────────

    public function users(Request $request)
    {
        $query = IdentityUser::orderBy('display_name');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('display_name',        'like', "%{$s}%")
                  ->orWhere('user_principal_name','like', "%{$s}%")
                  ->orWhere('mail',              'like', "%{$s}%")
                  ->orWhere('department',        'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('account_enabled', $request->status === 'enabled');
        }

        // By default, hide external (#EXT#) guest users
        if (! $request->boolean('show_external')) {
            $query->where('user_principal_name', 'not like', '%#EXT#%');
        }

        // Filter by allowed domains if configured
        $allowedDomains = \App\Models\AllowedDomain::getList();
        if (! empty($allowedDomains) && ! $request->boolean('show_external')) {
            $query->where(function ($q) use ($allowedDomains) {
                foreach ($allowedDomains as $domain) {
                    $q->orWhere('user_principal_name', 'like', "%@{$domain}");
                }
            });
        }

        $users    = $query->paginate(50)->withQueryString();
        $lastSync = IdentitySyncLog::where('status', 'completed')->latest()->first();

        $showExternal   = $request->boolean('show_external');
        $allowedDomains = \App\Models\AllowedDomain::getList();
        return view('admin.identity.users', compact('users', 'lastSync', 'showExternal', 'allowedDomains'));
    }

    public function userDetail(string $azureId)
    {
        $user        = IdentityUser::where('azure_id', $azureId)->firstOrFail();
        $licenses    = IdentityLicense::whereIn('sku_id', $user->assigned_licenses ?? [])->get();
        $allLicenses = IdentityLicense::orderBy('display_name')->get();
        $groups      = IdentityGroup::whereIn('azure_id', $user->member_of ?? [])->get();
        $allGroups   = IdentityGroup::orderBy('display_name')->get();

        return view('admin.identity.user-detail', compact('user', 'licenses', 'allLicenses', 'groups', 'allGroups'));
    }

    // ─────────────────────────────────────────────────────────────
    // Licenses
    // ─────────────────────────────────────────────────────────────

    public function licenses()
    {
        $licenses = IdentityLicense::orderBy('display_name')->get();
        $lastSync = IdentitySyncLog::where('status', 'completed')->latest()->first();
        return view('admin.identity.licenses', compact('licenses', 'lastSync'));
    }

    // ─────────────────────────────────────────────────────────────
    // Groups
    // ─────────────────────────────────────────────────────────────

    public function groups(Request $request)
    {
        $query = IdentityGroup::orderBy('display_name');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('display_name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $groups   = $query->paginate(50)->withQueryString();
        $lastSync = IdentitySyncLog::where('status', 'completed')->latest()->first();
        return view('admin.identity.groups', compact('groups', 'lastSync'));
    }

    /**
     * AJAX – return members of a group from local DB.
     */
    public function groupMembers(string $azureId)
    {
        $group   = IdentityGroup::where('azure_id', $azureId)->firstOrFail();
        $members = IdentityUser::whereJsonContains('member_of', $azureId)
                        ->orderBy('display_name')
                        ->get(['azure_id','display_name','user_principal_name','department','account_enabled']);

        return response()->json([
            'group'   => $group->display_name,
            'members' => $members,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Sync Logs
    // ─────────────────────────────────────────────────────────────

    public function syncLogs()
    {
        $logs = IdentitySyncLog::orderByDesc('created_at')->paginate(30);
        return view('admin.identity.sync-logs', compact('logs'));
    }

    // ─────────────────────────────────────────────────────────────
    // Sync trigger
    // ─────────────────────────────────────────────────────────────

    public function sync(Request $request)
    {
        $settings = Setting::get();

        if (! $settings->identity_sync_enabled) {
            return back()->with('error', 'Identity sync is disabled. Enable it under Settings → Identity (Graph).');
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            return back()->with('error', 'Microsoft Graph credentials are not configured. Go to Settings → Identity (Graph) to set them up.');
        }

        $force = $request->boolean('force');

        // ── Force-reset: clear stale lock + stuck logs ─────────────────────
        if ($force) {
            Cache::lock('sync_identity_running')->forceRelease();
            IdentitySyncLog::where('status', 'started')
                ->update([
                    'status'        => 'failed',
                    'error_message' => 'Force-reset via admin UI.',
                    'completed_at'  => now(),
                ]);
        }

        // ── Clean up orphaned "started" logs older than 2 hours ────────────
        // (A full sync of 1000+ users / 800+ groups can easily take 30–60 min)
        IdentitySyncLog::where('status', 'started')
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status'        => 'failed',
                'error_message' => 'Sync timed out — process exceeded 2-hour window.',
                'completed_at'  => now(),
            ]);

        // ── Prevent double-dispatch: check the real cache lock ─────────────
        if (! $force && SyncIdentityData::isRunning()) {
            return redirect()->route('admin.identity.sync-logs')
                ->with('info', 'A sync is already running. Wait for it to finish, or use Force Reset.');
        }

        // Also guard against DB log (belt-and-suspenders)
        $recentStarted = IdentitySyncLog::where('status', 'started')
            ->where('started_at', '>', now()->subHours(2))
            ->exists();

        if (! $force && $recentStarted) {
            return redirect()->route('admin.identity.sync-logs')
                ->with('info', 'A sync is already in progress. Use Force Reset if it appears stuck.');
        }

        ActivityLog::create([
            'model_type' => 'Identity',
            'model_id'   => 0,
            'action'     => 'synced',
            'changes'    => ['type' => 'identity_sync_started', 'forced' => $force],
            'user_id'    => Auth::id(),
        ]);

        // ── Spawn background CLI process ────────────────────────────────────
        $phpCli  = (new PhpExecutableFinder)->find() ?: 'php';
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/identity-sync.log');
        $flags   = $force ? ' --force' : '';

        $cmd = sprintf(
            'nohup %s %s identity:sync%s >> %s 2>&1 &',
            escapeshellarg($phpCli),
            escapeshellarg($artisan),
            $flags,
            escapeshellarg($logFile)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('/bin/bash -c ' . escapeshellarg($cmd), $descriptors, $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) { @fclose($pipe); }
            proc_close($proc);
        }

        return redirect()->route('admin.identity.sync-logs')
            ->with('info', 'Sync started in background — the page auto-refreshes.');
    }

    // ─────────────────────────────────────────────────────────────
    // Test Graph Connection (AJAX)
    // ─────────────────────────────────────────────────────────────

    public function testConnection(Request $request)
    {
        $request->validate([
            'tenant_id'     => 'required|string',
            'client_id'     => 'required|string',
            'client_secret' => 'nullable|string',
        ]);

        try {
            // Use the form secret; fall back to the saved secret when the field is left blank
            $secret  = $request->filled('client_secret')
                ? $request->client_secret
                : (Setting::get()->graph_client_secret ?? '');

            $graph   = new GraphService($request->tenant_id, $request->client_id, $secret);
            $orgName = $graph->testConnection();

            ActivityLog::create([
                'model_type' => 'Identity',
                'model_id'   => 0,
                'action'     => 'test_connection',
                'changes'    => ['result' => 'success', 'org' => $orgName],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => "Connected to: {$orgName}"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // User Actions (manage-identity)
    // ─────────────────────────────────────────────────────────────

    public function toggleUser(Request $request, string $azureId)
    {
        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();
        $graph = new GraphService();

        try {
            if ($user->account_enabled) {
                $graph->disableUser($azureId);
                $user->update(['account_enabled' => false]);
                $action = 'disabled';
            } else {
                $graph->enableUser($azureId);
                $user->update(['account_enabled' => true]);
                $action = 'enabled';
            }
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        ActivityLog::create([
            'model_type' => 'IdentityUser',
            'model_id'   => $user->id,
            'action'     => $action,
            'changes'    => ['user' => $user->user_principal_name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "User {$user->display_name} has been {$action}.");
    }

    public function resetPassword(Request $request, string $azureId)
    {
        $request->validate([
            'new_password'   => 'required|string|min:8',
            'force_change'   => 'boolean',
        ]);

        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        try {
            $graph = new GraphService();
            $graph->resetPassword($azureId, $request->new_password, (bool) $request->force_change);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        ActivityLog::create([
            'model_type' => 'IdentityUser',
            'model_id'   => $user->id,
            'action'     => 'password_reset',
            'changes'    => ['user' => $user->user_principal_name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Password reset for {$user->display_name}.");
    }

    public function assignLicense(Request $request, string $azureId)
    {
        $request->validate(['sku_id' => 'required|string']);

        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        try {
            $graph = new GraphService();
            $graph->assignLicense($azureId, $request->sku_id);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        $licenses = array_unique(array_merge($user->assigned_licenses ?? [], [$request->sku_id]));
        $user->update(['assigned_licenses' => $licenses, 'licenses_count' => count($licenses)]);

        return back()->with('success', 'License assigned.');
    }

    public function removeLicense(Request $request, string $azureId)
    {
        $request->validate(['sku_id' => 'required|string']);

        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        try {
            $graph = new GraphService();
            $graph->removeLicense($azureId, $request->sku_id);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        $licenses = array_values(array_filter($user->assigned_licenses ?? [], fn($s) => $s !== $request->sku_id));
        $user->update(['assigned_licenses' => $licenses, 'licenses_count' => count($licenses)]);

        return back()->with('success', 'License removed.');
    }

    public function addGroup(Request $request, string $azureId)
    {
        $request->validate(['group_id' => 'required|string']);

        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        try {
            $graph = new GraphService();
            $graph->addUserToGroup($azureId, $request->group_id);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        $groups = array_unique(array_merge($user->member_of ?? [], [$request->group_id]));
        $user->update(['member_of' => $groups, 'groups_count' => count($groups)]);

        return back()->with('success', 'User added to group.');
    }

    public function removeGroup(Request $request, string $azureId)
    {
        $request->validate(['group_id' => 'required|string']);

        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        try {
            $graph = new GraphService();
            $graph->removeUserFromGroup($azureId, $request->group_id);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        $groups = array_values(array_filter($user->member_of ?? [], fn($g) => $g !== $request->group_id));
        $user->update(['member_of' => $groups, 'groups_count' => count($groups)]);

        return back()->with('success', 'User removed from group.');
    }

    /**
     * Update user profile fields both in Graph and local DB.
     */
    public function updateProfile(Request $request, string $azureId)
    {
        $validated = $request->validate([
            'display_name'    => 'required|string|max:255',
            'job_title'       => 'nullable|string|max:255',
            'department'      => 'nullable|string|max:255',
            'company_name'    => 'nullable|string|max:255',
            'phone_number'    => 'nullable|string|max:50',
            'mobile_phone'    => 'nullable|string|max:50',
            'office_location' => 'nullable|string|max:100',
            'street_address'  => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:100',
            'postal_code'     => 'nullable|string|max:20',
            'country'         => 'nullable|string|max:100',
        ]);

        $user = IdentityUser::where('azure_id', $azureId)->firstOrFail();

        // Build Graph API payload (only non-null fields, mapped to Graph property names)
        $graphData = ['displayName' => $validated['display_name']];

        $fieldMap = [
            'job_title'       => 'jobTitle',
            'department'      => 'department',
            'company_name'    => 'companyName',
            'mobile_phone'    => 'mobilePhone',
            'office_location' => 'officeLocation',
            'street_address'  => 'streetAddress',
            'city'            => 'city',
            'postal_code'     => 'postalCode',
            'country'         => 'country',
        ];

        foreach ($fieldMap as $local => $graph) {
            $graphData[$graph] = $validated[$local] ?? null;
        }

        // businessPhones is an array in Graph
        $graphData['businessPhones'] = $validated['phone_number']
            ? [$validated['phone_number']]
            : [];

        try {
            $graph = new GraphService();
            $graph->updateUser($azureId, $graphData);
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }

        // Mirror changes in local DB
        $user->update([
            'display_name'    => $validated['display_name'],
            'job_title'       => $validated['job_title']       ?? null,
            'department'      => $validated['department']       ?? null,
            'company_name'    => $validated['company_name']     ?? null,
            'phone_number'    => $validated['phone_number']     ?? null,
            'mobile_phone'    => $validated['mobile_phone']     ?? null,
            'office_location' => $validated['office_location']  ?? null,
            'street_address'  => $validated['street_address']   ?? null,
            'city'            => $validated['city']             ?? null,
            'postal_code'     => $validated['postal_code']      ?? null,
            'country'         => $validated['country']          ?? null,
        ]);

        ActivityLog::create([
            'model_type' => 'IdentityUser',
            'model_id'   => $user->id,
            'action'     => 'profile_updated',
            'changes'    => ['user' => $user->user_principal_name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Profile updated for {$user->display_name}.");
    }

    public function destroyUser(string $azureId)
    {
        $user  = IdentityUser::where('azure_id', $azureId)->firstOrFail();
        $graph = new GraphService();

        try {
            $graph->deleteUser($azureId);
            
            ActivityLog::create([
                'model_type' => 'IdentityUser',
                'model_id'   => $user->id,
                'action'     => 'deleted',
                'changes'    => ['user' => $user->user_principal_name],
                'user_id'    => Auth::id(),
            ]);

            $user->delete();

            return redirect()->route('admin.identity.users')
                ->with('success', "User {$user->display_name} has been deleted from Azure AD.");
        } catch (\Exception $e) {
            return back()->with('error', $this->graphFriendlyError($e));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Return a human-friendly error string for Graph API failures.
     * Detects 403 "Authorization_RequestDenied" and explains the fix.
     */
    private function graphFriendlyError(\Exception $e): string
    {
        $msg = $e->getMessage();

        try {
            ActivityLog::create([
                'model_type' => 'GraphApi',
                'model_id'   => 0,
                'action'     => 'api_failed',
                'changes'    => [
                    'service' => 'MicrosoftGraph',
                    'message' => mb_substr($msg, 0, 1000),
                    'route'   => request()?->route()?->getName(),
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging mask the original failure.
        }

        if (str_contains($msg, 'Authorization_RequestDenied') || str_contains($msg, 'Insufficient privileges')) {
            return 'Azure AD permission denied. The app registration is missing write permissions. '
                 . 'Please add the User.ReadWrite.All (Application) permission in Azure AD portal '
                 . '→ App registrations → API permissions, then grant admin consent.';
        }

        return $msg;
    }
}
