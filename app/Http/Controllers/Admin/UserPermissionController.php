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

class UserPermissionController extends Controller
{
    public function edit(User $user)
    {
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already has every permission; custom permissions do not apply.');
        }

        $permissions = RolePermission::allPermissions();          // grouped: category => [slug => label]
        $roleGrants = RolePermission::forRole($user->role ?? ''); // slugs the role grants (reference only)
        $customSlugs = $user->permissions()->pluck('permission')->all();
        $customMode = ! empty($customSlugs);

        return view('admin.users.permissions', compact(
            'user',
            'permissions',
            'roleGrants',
            'customSlugs',
            'customMode',
        ));
    }

    public function update(Request $request, User $user)
    {
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already has every permission; custom permissions do not apply.');
        }

        $allSlugs = RolePermission::allSlugs();

        $submitted = (array) $request->input('permissions', []);
        // Keep only known permission slugs.
        $selected = array_values(array_intersect($submitted, $allSlugs));

        $old = $user->permissions()->orderBy('permission')->pluck('permission')->all();

        $now = now();
        $rows = array_map(fn ($slug) => [
            'user_id' => $user->id,
            'permission' => $slug,
            'effect' => 'grant',
            'created_at' => $now,
            'updated_at' => $now,
        ], $selected);

        DB::transaction(function () use ($user, $rows) {
            UserPermission::where('user_id', $user->id)->delete();
            if (! empty($rows)) {
                UserPermission::insert($rows);
            }
        });

        User::clearOverrideCache($user->id);

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'action' => 'user_permissions_updated',
            'changes' => ['old' => $old, 'new' => $selected],
            'user_id' => Auth::id(),
        ]);

        $msg = empty($selected)
            ? "Custom permissions cleared for {$user->name}; they now use the role default."
            : "Custom permissions saved for {$user->name} ({$user->name} now has ".count($selected).' permission'.(count($selected) === 1 ? '' : 's').').';

        return redirect()->route('admin.users.permissions.edit', $user)->with('success', $msg);
    }

    public function reset(User $user)
    {
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index');
        }

        $old = $user->permissions()->orderBy('permission')->pluck('permission')->all();

        UserPermission::where('user_id', $user->id)->delete();
        User::clearOverrideCache($user->id);

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'action' => 'user_permissions_reset',
            'changes' => ['old' => $old, 'new' => []],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.users.permissions.edit', $user)
            ->with('success', "Custom permissions cleared for {$user->name}. They now use the role default.");
    }
}
