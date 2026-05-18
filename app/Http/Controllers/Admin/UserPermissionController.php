<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserPermissionController extends Controller
{
    public function edit(User $user)
    {
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already has every permission; overrides do not apply.');
        }

        $permissions = RolePermission::allPermissions();          // grouped: category => [slug => label]
        $roleGrants = RolePermission::forRole($user->role ?? ''); // slugs the role currently grants

        // Build override map: slug => 'grant'|'deny'|'default'
        $existing = $user->permissions()->pluck('effect', 'permission')->all();
        $overrides = [];
        foreach (RolePermission::allSlugs() as $slug) {
            $overrides[$slug] = $existing[$slug] ?? 'default';
        }

        return view('admin.users.permissions', compact('user', 'permissions', 'roleGrants', 'overrides'));
    }

    public function update(Request $request, User $user)
    {
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already has every permission; overrides do not apply.');
        }

        $allSlugs = RolePermission::allSlugs();

        $data = $request->validate([
            'overrides' => ['required', 'array'],
            'overrides.*' => ['required', Rule::in(['default', 'grant', 'deny'])],
        ]);

        // Reject any slug that isn't a known permission.
        $submitted = array_intersect_key($data['overrides'], array_flip($allSlugs));

        $old = $user->permissions()->orderBy('permission')->get(['permission', 'effect'])
            ->mapWithKeys(fn ($r) => [$r->permission => $r->effect])->all();

        $now = now();
        $rows = [];
        foreach ($submitted as $slug => $state) {
            if ($state === 'grant' || $state === 'deny') {
                $rows[] = [
                    'user_id' => $user->id,
                    'permission' => $slug,
                    'effect' => $state,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($user, $rows) {
            UserPermission::where('user_id', $user->id)->delete();
            if (! empty($rows)) {
                UserPermission::insert($rows);
            }
        });

        User::clearOverrideCache($user->id);

        $new = collect($rows)
            ->mapWithKeys(fn ($r) => [$r['permission'] => $r['effect']])
            ->all();

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'action' => 'user_permissions_updated',
            'changes' => ['old' => $old, 'new' => $new],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.users.permissions.edit', $user)
            ->with('success', "Custom permissions for {$user->name} saved.");
    }
}
