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

/**
 * Per-user permission overrides, layered on top of the role.
 *
 * Each permission is one of three states for a user:
 *
 *   inherit — whatever the role says (no row)
 *   grant   — allowed even though the role doesn't say so
 *   deny    — refused even though the role does say so
 *
 * Deny wins, and effective = role ∪ grant − deny (see User::hasPermission).
 *
 * Before this, ANY row put the user into an allow-list mode where the role was
 * ignored entirely — so granting one extra permission silently revoked every
 * other one the person had, which is a surprising way to lose access. The
 * `effect` column was already on the table for this; nothing read it.
 */
class UserPermissionController extends Controller
{
    public function edit(User $user)
    {
        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already holds every permission; overrides do not apply.');
        }

        $permissions = RolePermission::allPermissions();          // category => [slug => label]
        $roleGrants = RolePermission::forRole($user->role ?? ''); // the baseline
        $rows = $user->permissions()->get(['permission', 'effect']);

        // slug => 'grant' | 'deny'; anything absent is inherit.
        $overrides = $rows->pluck('effect', 'permission')->all();

        $effective = $user->effectivePermissions();

        return view('admin.users.permissions', compact(
            'user',
            'permissions',
            'roleGrants',
            'overrides',
            'effective',
        ));
    }

    public function update(Request $request, User $user)
    {
        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.users.index')
                ->with('info', 'Super Admin already holds every permission; overrides do not apply.');
        }

        $allSlugs = RolePermission::allSlugs();
        $roleGrants = RolePermission::forRole($user->role ?? '');

        // The form posts one radio per permission: inherit | grant | deny.
        $submitted = (array) $request->input('effect', []);

        $before = $user->effectivePermissions();
        $beforeOverrides = $user->permissions()->get(['permission', 'effect'])
            ->pluck('effect', 'permission')->all();

        $rows = [];
        $now = now();

        foreach ($allSlugs as $slug) {
            $effect = $submitted[$slug] ?? 'inherit';

            if (! in_array($effect, ['grant', 'deny'], true)) {
                continue;
            }

            // A grant that the role already gives, or a deny of something the
            // role never gave, is a no-op row. Skipping them keeps the override
            // list to what is actually an exception — which is what makes the
            // screen readable, and what makes a later role change take effect
            // for this user instead of being frozen by a redundant row.
            $inRole = in_array($slug, $roleGrants, true);

            if (($effect === 'grant' && $inRole) || ($effect === 'deny' && ! $inRole)) {
                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'permission' => $slug,
                'effect' => $effect,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($user, $rows) {
            UserPermission::where('user_id', $user->id)->delete();

            if ($rows !== []) {
                UserPermission::insert($rows);
            }
        });

        User::clearOverrideCache($user->id);

        $afterOverrides = collect($rows)->pluck('effect', 'permission')->all();
        $after = $user->fresh()->effectivePermissions();

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'model_label' => $user->name,
            'action' => 'user_permissions_updated',
            'changes' => [
                'role' => $user->role,
                'overrides' => ['old' => $beforeOverrides, 'new' => $afterOverrides],
                'effective' => [
                    'gained' => array_values(array_diff($after, $before)),
                    'lost' => array_values(array_diff($before, $after)),
                ],
            ],
            'user_id' => Auth::id(),
        ]);

        $grants = count(array_filter($afterOverrides, fn ($e) => $e === 'grant'));
        $denies = count(array_filter($afterOverrides, fn ($e) => $e === 'deny'));

        $msg = $afterOverrides === []
            ? "Overrides cleared for {$user->name}; they now follow the ".User::roleLabel($user->role).' role exactly.'
            : "Saved for {$user->name}: {$grants} extra permission".($grants === 1 ? '' : 's').", {$denies} revoked.";

        return redirect()->route('admin.users.permissions.edit', $user)->with('success', $msg);
    }

    public function reset(User $user)
    {
        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.users.index');
        }

        $before = $user->effectivePermissions();
        $old = $user->permissions()->get(['permission', 'effect'])
            ->pluck('effect', 'permission')->all();

        UserPermission::where('user_id', $user->id)->delete();
        User::clearOverrideCache($user->id);

        ActivityLog::create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'model_label' => $user->name,
            'action' => 'user_permissions_reset',
            'changes' => [
                'role' => $user->role,
                'overrides' => ['old' => $old, 'new' => []],
                'effective' => [
                    'gained' => array_values(array_diff($user->fresh()->effectivePermissions(), $before)),
                    'lost' => array_values(array_diff($before, $user->fresh()->effectivePermissions())),
                ],
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.users.permissions.edit', $user)
            ->with('success', "Overrides cleared for {$user->name}. They now follow the ".User::roleLabel($user->role).' role exactly.');
    }
}
