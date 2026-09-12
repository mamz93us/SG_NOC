<?php

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Permission resolution: the role is the baseline, per-user rows layer on top,
 * deny wins.
 *
 * These rules decide what all 1,135 permission-gated routes do, so they are
 * covered even though most of this codebase is not. See RbacTestSchema for why
 * the tables are built by hand instead of by RefreshDatabase.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();

    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();

    Role::create([
        'slug' => 'super_admin', 'name' => 'Super Admin',
        'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
        'is_super' => true, 'is_system' => true, 'sort_order' => 10,
    ]);

    Role::create([
        'slug' => 'viewer', 'name' => 'Viewer',
        'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
        'is_super' => false, 'is_system' => true, 'sort_order' => 40,
    ]);

    $now = now();
    RolePermission::insert([
        ['role' => 'viewer', 'permission' => 'view-branches', 'created_at' => $now, 'updated_at' => $now],
        ['role' => 'viewer', 'permission' => 'view-contacts', 'created_at' => $now, 'updated_at' => $now],
    ]);

    Role::clearCache();
    RolePermission::clearCache();
});

afterEach(fn () => RbacTestSchema::drop());

function rbacUser(string $roleSlug): User
{
    static $n = 0;
    $n++;

    return User::create([
        'name' => "Test {$n}",
        'email' => "test{$n}@example.com",
        'password' => 'x',
        'role' => $roleSlug,
    ]);
}

// ── Role baseline ───────────────────────────────────────────────

it('grants what the role grants and nothing else', function () {
    $user = rbacUser('viewer');

    expect($user->hasPermission('view-branches'))->toBeTrue();
    expect($user->hasPermission('view-contacts'))->toBeTrue();
    expect($user->hasPermission('manage-branches'))->toBeFalse();
});

it('grants everything to a superuser role, including permissions no row mentions', function () {
    $user = rbacUser('super_admin');

    expect($user->hasPermission('view-branches'))->toBeTrue();
    expect($user->hasPermission('manage-users'))->toBeTrue();
    // In no role_permissions row for anyone. is_super is an implicit grant,
    // which is what stops a newly added permission from locking out the one
    // account able to hand it out.
    expect($user->hasPermission('some-permission-added-next-year'))->toBeTrue();
});

it('grants nothing to a user whose role slug has no row', function () {
    $user = rbacUser('role_that_was_deleted');

    expect($user->hasPermission('view-branches'))->toBeFalse();
    expect($user->isSuperAdmin())->toBeFalse();
    // Unknown role reads as portal-only: /portal is unguarded, /admin is not, so
    // this is the safe direction to fail.
    expect($user->usesPortal())->toBeTrue();
});

// ── Overrides layer on top of the role ──────────────────────────

it('adds a granted permission without discarding the role baseline', function () {
    $user = rbacUser('viewer');

    UserPermission::create([
        'user_id' => $user->id, 'permission' => 'view-credentials', 'effect' => 'grant',
    ]);
    User::clearOverrideCache($user->id);

    expect($user->hasPermission('view-credentials'))->toBeTrue();
    // The point of the change: under the old allow-list semantics, adding this
    // one row silently revoked everything else the person had.
    expect($user->hasPermission('view-branches'))->toBeTrue();
    expect($user->hasPermission('view-contacts'))->toBeTrue();
});

it('revokes a role permission with a deny row', function () {
    $user = rbacUser('viewer');

    UserPermission::create([
        'user_id' => $user->id, 'permission' => 'view-contacts', 'effect' => 'deny',
    ]);
    User::clearOverrideCache($user->id);

    expect($user->hasPermission('view-contacts'))->toBeFalse();
    expect($user->hasPermission('view-branches'))->toBeTrue();
});

it('lets deny win over a grant for the same permission', function () {
    $user = rbacUser('viewer');

    $now = now();
    UserPermission::insert([
        ['user_id' => $user->id, 'permission' => 'view-credentials', 'effect' => 'grant', 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $user->id, 'permission' => 'view-credentials', 'effect' => 'deny', 'created_at' => $now, 'updated_at' => $now],
    ]);
    User::clearOverrideCache($user->id);

    // The UI cannot produce this pair and the production unique index forbids it,
    // but if it ever exists the safe reading is refusal.
    expect($user->hasPermission('view-credentials'))->toBeFalse();
});

it('ignores overrides entirely for a superuser', function () {
    $user = rbacUser('super_admin');

    UserPermission::create([
        'user_id' => $user->id, 'permission' => 'view-branches', 'effect' => 'deny',
    ]);
    User::clearOverrideCache($user->id);

    // A deny must not cut down a superuser, or one mis-click locks the only
    // account that could undo it.
    expect($user->hasPermission('view-branches'))->toBeTrue();
});

// ── The screen and the gate must agree ──────────────────────────

it('reports the same effective set that hasPermission resolves', function () {
    $user = rbacUser('viewer');

    $now = now();
    UserPermission::insert([
        ['user_id' => $user->id, 'permission' => 'view-credentials', 'effect' => 'grant', 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $user->id, 'permission' => 'view-contacts', 'effect' => 'deny', 'created_at' => $now, 'updated_at' => $now],
    ]);
    User::clearOverrideCache($user->id);

    $effective = $user->effectivePermissions();

    expect($effective)->toContain('view-branches');       // from the role
    expect($effective)->toContain('view-credentials');    // granted
    expect($effective)->not->toContain('view-contacts');  // denied

    // If these diverged, an admin would tick a box that changes nothing.
    expect($user->hasPermission('view-branches'))->toBeTrue();
    expect($user->hasPermission('view-credentials'))->toBeTrue();
    expect($user->hasPermission('view-contacts'))->toBeFalse();
});

// ── syncRoles() must not destroy what it cannot show ────────────

it('keeps a grant for a permission missing from the registry when the matrix is saved', function () {
    // The bug this replaces: a subsystem shipped a route gate and a seeding
    // migration without registering the slug; the matrix truncated the table and
    // re-inserted only registered slugs; the grant was destroyed and, with no
    // checkbox for it, could never be restored.
    RolePermission::create(['role' => 'viewer', 'permission' => 'some-unregistered-slug']);
    RolePermission::clearCache();

    expect(RolePermission::unregisteredSlugs())->toContain('some-unregistered-slug');

    RolePermission::syncRoles(['viewer' => ['view-branches']]);

    $after = RolePermission::forRole('viewer');

    expect($after)->toContain('view-branches');           // ticked
    expect($after)->not->toContain('view-contacts');      // unticked
    expect($after)->toContain('some-unregistered-slug');  // survived
});

it('does not touch a role that was not part of the save', function () {
    RolePermission::create(['role' => 'admin', 'permission' => 'manage-branches']);
    RolePermission::clearCache();

    RolePermission::syncRoles(['viewer' => ['view-branches']]);

    expect(RolePermission::forRole('admin'))->toContain('manage-branches');
});

it('can revoke every registered permission from a role', function () {
    RolePermission::syncRoles(['viewer' => []]);

    expect(RolePermission::forRole('viewer'))->toBe([]);
});

it('ignores a submitted slug that is not in the registry', function () {
    // Otherwise the matrix would be a way to write arbitrary strings into the
    // permission table from a crafted POST.
    RolePermission::syncRoles(['viewer' => ['view-branches', 'not-a-real-permission']]);

    expect(RolePermission::forRole('viewer'))->toBe(['view-branches']);
});
