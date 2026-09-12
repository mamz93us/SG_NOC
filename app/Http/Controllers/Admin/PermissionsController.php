<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The role × permission matrix.
 *
 * Two behaviours changed here, both of which were losing grants:
 *
 *  1. The role list was the hardcoded array
 *     ['super_admin','admin','hr','viewer','browser_user'] — it omitted
 *     `marketing`, so that role's permissions could not be edited at all, and it
 *     could never show a custom role. It comes from the roles table now.
 *
 *  2. update() called RolePermission::truncate() and re-inserted only the slugs
 *     in allPermissions(). Any grant for a slug missing from that registry was
 *     destroyed on every save — which is what had happened to
 *     view-phone-firmware, manage-phones, view-server-status, manage-radius,
 *     view-voice-mesh and a dozen others: their own migrations seeded them,
 *     the first matrix save deleted them, and because they were absent from the
 *     registry there was no checkbox left to put them back. Saving now goes
 *     through RolePermission::syncRoles(), which only ever deletes rows for
 *     slugs it is also able to display.
 */
class PermissionsController extends Controller
{
    public function index()
    {
        $roles = Role::assignable()->get();
        $permissions = RolePermission::allPermissions();   // grouped
        $allSlugs = RolePermission::allSlugs();

        // role slug => [permission slug => bool]
        $matrix = [];
        foreach ($roles as $role) {
            $granted = $role->is_super ? $allSlugs : RolePermission::forRole($role->slug);

            foreach ($allSlugs as $slug) {
                $matrix[$role->slug][$slug] = in_array($slug, $granted, true);
            }
        }

        // Grants that exist in the database for slugs this page cannot show. The
        // registry is meant to be complete; when it isn't, say so here rather
        // than letting the gap stay invisible.
        $unregistered = RolePermission::unregisteredSlugs();

        return view('admin.permissions.index', compact(
            'roles',
            'permissions',
            'allSlugs',
            'matrix',
            'unregistered',
        ));
    }

    public function update(Request $request)
    {
        $roles = Role::assignable()->get();
        $submittedRoles = (array) $request->input('permissions', []);

        // Which roles the form actually rendered, from the hidden markers. An
        // unchecked checkbox posts nothing, so `permissions[viewer]` being absent
        // is ambiguous on its own — it means either "Viewer wasn't on the page"
        // or "every box for Viewer was cleared". Without this list the second
        // case was unreachable: you could never revoke a role's last permission.
        $present = array_flip((array) $request->input('roles_present', []));

        $before = [];
        $after = [];
        $grants = [];

        foreach ($roles as $role) {
            // A superuser role's rows are never consulted (hasPermission()
            // short-circuits on is_super), so leave them untouched rather than
            // writing a set that only looks authoritative.
            if ($role->is_super) {
                continue;
            }

            // A role the form didn't render — one added by someone else between
            // this page load and the save — keeps what it has.
            if (! isset($present[$role->slug])) {
                continue;
            }

            $ticked = array_keys(array_filter((array) ($submittedRoles[$role->slug] ?? [])));
            $granted = array_values(array_intersect($ticked, RolePermission::allSlugs()));

            $existing = RolePermission::forRole($role->slug);

            $before[$role->slug] = $existing;
            $after[$role->slug] = $granted;
            $grants[$role->slug] = $granted;
        }

        RolePermission::syncRoles($grants);
        User::clearOverrideCache();

        // Log the delta, not the whole matrix: "admin lost manage-attendance" is
        // the reviewable fact, and a dump of 130 slugs per role buries it.
        $delta = [];
        foreach ($after as $slug => $granted) {
            $added = array_values(array_diff($granted, $before[$slug]));
            $removed = array_values(array_diff($before[$slug], $granted));

            if ($added === [] && $removed === []) {
                continue;
            }

            $delta[$slug] = array_filter([
                'added' => $added,
                'removed' => $removed,
            ]);
        }

        ActivityLog::create([
            'model_type' => Role::class,
            'model_id' => 0,
            'model_label' => 'Role permission matrix',
            'action' => 'role_permissions_updated',
            'changes' => $delta === []
                ? ['note' => 'saved with no changes']
                : $delta,
            'user_id' => Auth::id(),
        ]);

        $changedRoles = count($delta);

        return redirect()->route('admin.permissions.index')->with(
            'success',
            $changedRoles === 0
                ? 'No permission changes to save.'
                : 'Role permissions updated for '.$changedRoles.' role'.($changedRoles === 1 ? '' : 's').'.'
        );
    }
}
