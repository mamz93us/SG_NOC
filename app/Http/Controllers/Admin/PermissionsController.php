<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PermissionsController extends Controller
{
    public function index()
    {
        $roles       = ['super_admin', 'admin', 'viewer', 'browser_user'];
        $permissions = RolePermission::allPermissions();   // grouped
        $allSlugs    = RolePermission::allSlugs();

        // Build a quick-lookup: role => [slug => bool]
        $matrix = [];
        foreach ($roles as $role) {
            $granted = RolePermission::forRole($role);
            foreach ($allSlugs as $slug) {
                $matrix[$role][$slug] = in_array($slug, $granted);
            }
        }

        return view('admin.permissions.index', compact('roles', 'permissions', 'allSlugs', 'matrix'));
    }

    public function update(Request $request)
    {
        $roles    = ['super_admin', 'admin', 'viewer', 'browser_user'];
        $allSlugs = RolePermission::allSlugs();

        // super_admin always keeps manage-users and manage-permissions
        $forced = ['manage-users', 'manage-permissions'];

        // Sticky baseline: even if the matrix isn't submitted for a role
        // (e.g. browser_user is hidden in the UI), keep these defaults so
        // a save never accidentally wipes out role permissions.
        $sticky = [
            'browser_user' => ['view-browser-portal'],
        ];

        $now  = now();
        $rows = [];

        foreach ($roles as $role) {
            $submitted = $request->input("permissions.{$role}", []);
            $stickyForRole = $sticky[$role] ?? [];

            foreach ($allSlugs as $slug) {
                $has = isset($submitted[$slug])
                    || ($role === 'super_admin' && in_array($slug, $forced))
                    || in_array($slug, $stickyForRole);

                if ($has) {
                    $rows[] = [
                        'role'       => $role,
                        'permission' => $slug,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // Snapshot before replacing
        $summary = [];
        foreach ($roles as $role) {
            $summary[$role] = array_values(array_filter(
                array_map(fn($r) => $r['role'] === $role ? $r['permission'] : null, $rows)
            ));
        }

        // Replace all permissions
        RolePermission::truncate();
        if (!empty($rows)) {
            RolePermission::insert($rows);
        }
        RolePermission::clearCache();

        ActivityLog::create([
            'model_type' => 'RolePermission',
            'model_id'   => 0,
            'action'     => 'updated',
            'changes'    => $summary,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.permissions.index')
            ->with('success', 'Role permissions updated successfully.');
    }
}
