<?php

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Which app surfaces a role reaches, and where sign-in sends it.
 *
 * This replaces User::usesPortal() / homeRoute(), which hardcoded the
 * browser_user|hr|marketing list — so a new role could not be portal-only, and
 * nothing but marketing could land on the marketing host.
 *
 * The seeded expectations below are the behaviour that was hardcoded before, so
 * these double as a check that the migration is a no-op for existing logins.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();
    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();
});

afterEach(fn () => RbacTestSchema::drop());

function makeRole(array $attributes): Role
{
    $role = Role::create(array_merge([
        'slug' => 'test_role',
        'name' => 'Test Role',
        'surfaces' => ['noc_admin'],
        'landing' => 'noc_admin',
        'is_super' => false,
        'is_system' => false,
        'sort_order' => 100,
    ], $attributes));

    Role::clearCache();

    return $role->fresh();
}

function userOn(Role $role): User
{
    static $n = 0;
    $n++;

    return User::create([
        'name' => "U{$n}",
        'email' => "u{$n}@example.com",
        'password' => 'x',
        'role' => $role->slug,
    ]);
}

// ── Multi-surface selection ─────────────────────────────────────

it('lets one role hold several surfaces at once', function () {
    $role = makeRole([
        'surfaces' => ['noc_admin', 'hr_portal', 'browser_portal'],
        'landing' => 'hr_portal',
    ]);

    expect($role->hasSurface('noc_admin'))->toBeTrue();
    expect($role->hasSurface('hr_portal'))->toBeTrue();
    expect($role->hasSurface('browser_portal'))->toBeTrue();
    expect($role->hasSurface('marketing_portal'))->toBeFalse();
});

it('drops a surface key that is not a real surface', function () {
    // Guards against a stale value left in the JSON column by an older release
    // turning into a landing route that does not exist.
    $role = makeRole(['surfaces' => ['noc_admin', 'some_removed_surface']]);

    expect($role->surfaceList())->toBe(['noc_admin']);
});

// ── usesPortal() is now a consequence of the surfaces ───────────

it('treats a role without NOC Admin as portal-only', function () {
    $role = makeRole(['surfaces' => ['noc_portal', 'browser_portal'], 'landing' => 'noc_portal']);

    expect($role->usesPortal())->toBeTrue();
    expect(userOn($role)->usesPortal())->toBeTrue();
});

it('treats a role with NOC Admin as an admin role, even alongside portal surfaces', function () {
    $role = makeRole([
        'surfaces' => ['noc_admin', 'hr_portal'],
        'landing' => 'noc_admin',
    ]);

    // The combination the old hardcoded list could not express: HR portal access
    // AND the admin area.
    expect($role->usesPortal())->toBeFalse();
    expect(userOn($role)->usesPortal())->toBeFalse();
});

// ── Landing ─────────────────────────────────────────────────────

it('lands on the chosen surface', function () {
    expect(makeRole(['surfaces' => ['noc_admin'], 'landing' => 'noc_admin'])->landingRoute())
        ->toBe('admin.dashboard');

    expect(makeRole(['slug' => 'r2', 'surfaces' => ['noc_portal'], 'landing' => 'noc_portal'])->landingRoute())
        ->toBe('portal.index');

    expect(makeRole(['slug' => 'r3', 'surfaces' => ['hr_portal'], 'landing' => 'hr_portal'])->landingRoute())
        ->toBe('portal.hr.index');

    expect(makeRole(['slug' => 'r4', 'surfaces' => ['marketing_portal'], 'landing' => 'marketing_portal'])->landingRoute())
        ->toBe('portal.marketing.dashboard');
});

it('falls back to a granted surface when the landing choice is not one of them', function () {
    // Reachable if someone edits the row directly, or a surface is later removed
    // from the role without updating `landing`. Landing on a surface the host
    // isolation then 404s is worse than landing somewhere slightly unexpected.
    $role = makeRole(['surfaces' => ['noc_portal'], 'landing' => 'noc_admin']);

    expect($role->landingRoute())->toBe('portal.index');
});

it('falls back to the portal hub for a role with no surfaces at all', function () {
    $role = makeRole(['surfaces' => [], 'landing' => 'noc_admin']);

    // Never admin.dashboard: /admin bounces a portal user to /portal, and a
    // surfaceless role landing there would ping-pong.
    expect($role->landingRoute())->toBe('portal.index');
    expect($role->usesPortal())->toBeTrue();
});

it('sends a user with an unknown role slug to the portal hub', function () {
    $user = User::create([
        'name' => 'Orphan', 'email' => 'orphan@example.com',
        'password' => 'x', 'role' => 'no_such_role',
    ]);

    expect($user->homeRoute())->toBe('portal.index');
});

// ── The six shipped roles keep their old behaviour ──────────────

it('reproduces the landing behaviour that was hardcoded before roles were rows', function () {
    // Previously: homeRoute() sent marketing to the marketing portal, anything in
    // (browser_user, hr, marketing) to portal.index, and everyone else to
    // admin.dashboard.
    $expected = [
        ['super_admin', ['noc_admin', 'noc_portal'], 'noc_admin', 'admin.dashboard', false],
        ['admin', ['noc_admin', 'noc_portal'], 'noc_admin', 'admin.dashboard', false],
        ['viewer', ['noc_admin'], 'noc_admin', 'admin.dashboard', false],
        ['hr', ['noc_portal', 'hr_portal'], 'noc_portal', 'portal.index', true],
        ['browser_user', ['noc_portal', 'browser_portal'], 'noc_portal', 'portal.index', true],
        ['marketing', ['noc_portal', 'marketing_portal'], 'marketing_portal', 'portal.marketing.dashboard', true],
    ];

    foreach ($expected as [$slug, $surfaces, $landing, $route, $portalOnly]) {
        $role = makeRole([
            'slug' => $slug, 'name' => $slug,
            'surfaces' => $surfaces, 'landing' => $landing,
            'is_super' => $slug === 'super_admin',
        ]);

        expect($role->landingRoute())->toBe($route);
        expect($role->usesPortal())->toBe($portalOnly);
        expect(userOn($role)->homeRoute())->toBe($route);
    }
});

// ── Labels and locking ──────────────────────────────────────────

it('takes the display label from the role row', function () {
    makeRole(['slug' => 'warehouse_lead', 'name' => 'Warehouse Lead']);

    expect(User::roleLabel('warehouse_lead'))->toBe('Warehouse Lead');
});

it('humanises an unknown slug rather than showing it raw', function () {
    expect(User::roleLabel('some_old_slug'))->toBe('Some Old Slug');
    expect(User::roleLabel(null))->toBe('—');
});

it('marks a system role as locked and a custom one as not', function () {
    expect(makeRole(['slug' => 'built_in', 'is_system' => true])->isLocked())->toBeTrue();
    expect(makeRole(['slug' => 'custom', 'is_system' => false])->isLocked())->toBeFalse();
});

it('reads the superuser flag from the row rather than the slug', function () {
    // So a renamed or additional superuser role works, and so the check has one
    // source instead of five inline `=== 'super_admin'` comparisons.
    $role = makeRole(['slug' => 'owner', 'name' => 'Owner', 'is_super' => true]);

    expect(userOn($role)->isSuperAdmin())->toBeTrue();

    $plain = makeRole(['slug' => 'plain', 'name' => 'Plain', 'is_super' => false]);

    expect(userOn($plain)->isSuperAdmin())->toBeFalse();
});

it('gives every role a badge class, custom ones included', function () {
    expect(makeRole(['slug' => 'super_admin', 'is_super' => true])->badgeClass())->toBe('bg-danger');
    expect(makeRole(['slug' => 'anything_new'])->badgeClass())->toStartWith('bg-');
});
