<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Role CRUD.
 *
 * Roles used to be six hardcoded strings, so adding one meant a code change in
 * six files. They are rows now (see the create_roles_table migration) and this
 * is the UI over them: name, description, which app surfaces the role reaches,
 * which one it lands on, and its permission set.
 *
 * Two things are deliberately not editable:
 *  - a system role's SLUG, because code and seeding migrations reference it by
 *    string (`where('role', 'hr')` appears in the notification router, the
 *    approval chain and a dozen jobs);
 *  - the is_super flag, because a UI that can mint a second superuser is a
 *    privilege-escalation path, and the one that exists is enough.
 */
class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::assignable()->get();

        // Headline numbers per role, so the list says what a role actually is
        // rather than just its name.
        $userCounts = User::select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $permissionCounts = RolePermission::select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $totalPermissions = count(RolePermission::allSlugs());

        // Slugs granted in the database but missing from the registry. These are
        // the ones the matrix cannot show; surfacing the count here is how anyone
        // finds out a subsystem shipped a gate without registering it.
        $unregistered = RolePermission::unregisteredSlugs();

        return view('admin.roles.index', compact(
            'roles',
            'userCounts',
            'permissionCounts',
            'totalPermissions',
            'unregistered',
        ));
    }

    public function create()
    {
        $role = new Role([
            'surfaces' => ['noc_admin'],
            'landing' => 'noc_admin',
            'sort_order' => 100,
        ]);

        return view('admin.roles.form', [
            'role' => $role,
            'permissions' => RolePermission::allPermissions(),
            'granted' => [],
            'copyFrom' => Role::assignable()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $role = new Role;
        $role->slug = $data['slug'];
        $this->fill($role, $data);
        $role->is_system = false;
        $role->save();

        // Starting from an existing role's permissions is how most new roles get
        // made ("like Viewer, plus attendance"), and it beats ticking 130 boxes.
        $granted = $this->resolveInitialPermissions($request, $data);
        RolePermission::syncRoles([$role->slug => $granted]);

        $this->audit('role_created', $role, [
            'slug' => $role->slug,
            'name' => $role->name,
            'surfaces' => $role->surfaceList(),
            'landing' => $role->landing,
            'permissions' => $granted,
            'copied_from' => $request->input('copy_from') ?: null,
        ]);

        return redirect()->route('admin.roles.edit', $role)
            ->with('success', "Role “{$role->name}” created with ".count($granted).' permission'.(count($granted) === 1 ? '' : 's').'.');
    }

    public function edit(Role $role)
    {
        return view('admin.roles.form', [
            'role' => $role,
            'permissions' => RolePermission::allPermissions(),
            'granted' => $role->permissionSlugs(),
            'copyFrom' => Role::assignable()->where('id', '!=', $role->id)->get(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);

        $before = [
            'name' => $role->name,
            'surfaces' => $role->surfaceList(),
            'landing' => $role->landing,
            'permissions' => $role->permissionSlugs(),
        ];

        // The slug is set once, at creation, and never changed — for a custom role
        // as much as a system one. It is a foreign key in all but name: it is what
        // `users.role` and `role_permissions.role` store, and it is also read out
        // of `notification_rules.recipient_role` and `settings.sso_default_role`.
        // Rewriting it would have to cascade through all four, and each one missed
        // fails silently — the role's permissions would vanish and its users would
        // resolve to no role at all, which reads as portal-only. The NAME is what
        // people see and that stays freely editable; a genuinely wrong slug is
        // fixed by deleting the role (allowed while nobody holds it) and
        // recreating it.
        $this->fill($role, $data);
        $role->save();

        $granted = $this->submittedPermissions($request);

        // A superuser role's permission rows are never consulted — hasPermission()
        // short-circuits on is_super — so writing them would only create a
        // misleading matrix. Leave them alone.
        if (! $role->is_super) {
            RolePermission::syncRoles([$role->slug => $granted]);
        }

        User::clearOverrideCache();
        Role::clearCache();

        $after = [
            'name' => $role->name,
            'surfaces' => $role->surfaceList(),
            'landing' => $role->landing,
            'permissions' => $role->is_super ? $before['permissions'] : $granted,
        ];

        $this->audit('role_updated', $role, [
            'slug' => $role->slug,
            'old' => $before,
            'new' => $after,
            'added' => array_values(array_diff($after['permissions'], $before['permissions'])),
            'removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
        ]);

        return redirect()->route('admin.roles.edit', $role)
            ->with('success', "Role “{$role->name}” updated.");
    }

    public function destroy(Role $role)
    {
        if ($role->isLocked()) {
            return redirect()->route('admin.roles.index')
                ->with('error', "“{$role->name}” is a built-in role and cannot be deleted. You can still change its name, surfaces and permissions.");
        }

        // Deleting a role out from under its users would leave `users.role`
        // pointing at nothing — and usesPortal() treats an unknown role as
        // portal-only, so those people would quietly lose the admin area.
        $inUse = User::where('role', $role->slug)->count();

        if ($inUse > 0) {
            return redirect()->route('admin.roles.index')
                ->with('error', "“{$role->name}” is assigned to {$inUse} user".($inUse === 1 ? '' : 's').'. Move them to another role first.');
        }

        $snapshot = [
            'slug' => $role->slug,
            'name' => $role->name,
            'surfaces' => $role->surfaceList(),
            'landing' => $role->landing,
            'permissions' => $role->permissionSlugs(),
        ];

        DB::transaction(function () use ($role) {
            RolePermission::where('role', $role->slug)->delete();
            $role->delete();
        });

        RolePermission::clearCache();
        Role::clearCache();

        $this->audit('role_deleted', null, $snapshot);

        return redirect()->route('admin.roles.index')
            ->with('success', "Role “{$snapshot['name']}” deleted.");
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $surfaces = array_keys(Role::SURFACES);

        $rules = [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'surfaces' => 'required|array|min:1',
            'surfaces.*' => ['string', Rule::in($surfaces)],
            'landing' => ['required', 'string', Rule::in($surfaces)],
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ];

        // Only on create: see update() for why the slug never changes afterwards.
        if (! $role) {
            $rules['slug'] = [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'slug'),
            ];
        }

        $data = $request->validate($rules, [
            'slug.regex' => 'The slug must be lowercase letters, digits and underscores, starting with a letter (e.g. warehouse_lead).',
            'surfaces.required' => 'Pick at least one surface — a role that reaches nothing cannot sign in anywhere.',
        ]);

        // The landing page must be one of the granted surfaces, or sign-in sends
        // the user somewhere their role cannot reach and the host isolation 404s
        // them on their own home page.
        if (! in_array($data['landing'], $data['surfaces'], true)) {
            $first = Role::SURFACES[$data['surfaces'][0]] ?? $data['surfaces'][0];

            throw \Illuminate\Validation\ValidationException::withMessages([
                'landing' => 'The landing page must be one of the selected surfaces. Either tick “'.(Role::SURFACES[$data['landing']] ?? $data['landing'])."” above, or land on “{$first}” instead.",
            ]);
        }

        return $data;
    }

    private function fill(Role $role, array $data): void
    {
        $role->name = $data['name'];
        $role->description = $data['description'] ?? null;
        $role->surfaces = array_values($data['surfaces']);
        $role->landing = $data['landing'];
        $role->sort_order = $data['sort_order'] ?? 100;
    }

    /** @return array<int,string> */
    private function submittedPermissions(Request $request): array
    {
        $submitted = (array) $request->input('permissions', []);

        // The form posts permissions[slug] = 1 for ticked boxes.
        $slugs = array_keys(array_filter($submitted));

        return array_values(array_intersect($slugs, RolePermission::allSlugs()));
    }

    /** @return array<int,string> */
    private function resolveInitialPermissions(Request $request, array $data): array
    {
        $submitted = $this->submittedPermissions($request);

        if ($submitted !== []) {
            return $submitted;
        }

        $copyFrom = $request->input('copy_from');

        return $copyFrom ? RolePermission::forRole($copyFrom) : [];
    }

    private function audit(string $action, ?Role $role, array $changes): void
    {
        ActivityLog::create([
            'model_type' => Role::class,
            'model_id' => $role?->id ?? 0,
            'model_label' => $role?->name ?? ($changes['name'] ?? null),
            'action' => $action,
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
