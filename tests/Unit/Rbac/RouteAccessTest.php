<?php

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\RouteAccess;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * RouteAccess answers "may this user open that page?" from the page's own route
 * gates, and the admin menu shows a link only when it says yes. Its answer has
 * to be the one EnsurePermission gives when the link is clicked, or the menu
 * hides pages people may open and shows pages that turn them away — the bug it
 * replaced.
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
        'slug' => 'it', 'name' => 'IT',
        'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
        'is_super' => false, 'is_system' => false, 'sort_order' => 50,
    ]);

    $now = now();
    RolePermission::insert([
        ['role' => 'it', 'permission' => 'view-branches', 'created_at' => $now, 'updated_at' => $now],
        ['role' => 'it', 'permission' => 'view-contacts', 'created_at' => $now, 'updated_at' => $now],
    ]);

    Role::clearCache();
    RolePermission::clearCache();

    $ok = fn () => 'ok';
    Route::get('/_route-access/one', $ok)->middleware('permission:view-branches')->name('route-access.one');
    Route::get('/_route-access/either', $ok)->middleware('permission:manage-branches,view-contacts')->name('route-access.either');
    Route::get('/_route-access/both', $ok)->middleware(['permission:view-branches', 'permission:manage-branches'])->name('route-access.both');
    Route::get('/_route-access/open', $ok)->name('route-access.open');
    Route::getRoutes()->refreshNameLookups();
});

afterEach(fn () => RbacTestSchema::drop());

function routeAccessUser(string $roleSlug): User
{
    static $n = 0;
    $n++;

    return User::create([
        'name' => "Route Access {$n}",
        'email' => "route-access{$n}@example.com",
        'password' => 'x',
        'role' => $roleSlug,
    ]);
}

it('passes a route whose one gate the role holds', function () {
    expect(RouteAccess::allows(routeAccessUser('it'), 'route-access.one'))->toBeTrue();
});

it('treats the slugs in one gate as alternatives', function () {
    // permission:manage-branches,view-contacts — the role holds only the second.
    expect(RouteAccess::allows(routeAccessUser('it'), 'route-access.either'))->toBeTrue();
});

it('requires every gate when a route has several', function () {
    $user = routeAccessUser('it');

    expect(RouteAccess::allows($user, 'route-access.both'))->toBeFalse();

    UserPermission::create(['user_id' => $user->id, 'permission' => 'manage-branches', 'effect' => 'grant']);
    User::clearOverrideCache($user->id);

    expect(RouteAccess::allows($user, 'route-access.both'))->toBeTrue();
});

it('lets a per-user deny take a page away', function () {
    $user = routeAccessUser('it');

    UserPermission::create(['user_id' => $user->id, 'permission' => 'view-branches', 'effect' => 'deny']);
    User::clearOverrideCache($user->id);

    expect(RouteAccess::allows($user, 'route-access.one'))->toBeFalse();
});

it('passes a superuser through every gate', function () {
    expect(RouteAccess::allows(routeAccessUser('super_admin'), 'route-access.both'))->toBeTrue();
});

it('passes a route with no permission gate for anyone signed in, and nobody else', function () {
    expect(RouteAccess::allows(routeAccessUser('it'), 'route-access.open'))->toBeTrue();
    expect(RouteAccess::allows(null, 'route-access.open'))->toBeFalse();
});

it('hides a route name that does not exist instead of throwing', function () {
    expect(RouteAccess::allows(routeAccessUser('super_admin'), 'route-access.no-such-route'))->toBeFalse();
});

it('shows a menu when any one of its pages is open to the user', function () {
    $user = routeAccessUser('it');

    expect(RouteAccess::allowsAny($user, 'route-access.both', 'route-access.one'))->toBeTrue();
    expect(RouteAccess::allowsAny($user, 'route-access.both'))->toBeFalse();
});

it('backs the @canroute Blade condition with the signed-in user', function () {
    $this->actingAs(routeAccessUser('it'));

    expect(Blade::check('canroute', 'route-access.one'))->toBeTrue();
    expect(Blade::check('canroute', 'route-access.both'))->toBeFalse();
    expect(Blade::check('canroute', 'route-access.both', 'route-access.either'))->toBeTrue();
});

it('agrees with EnsurePermission on every page and user', function (string $role, ?string $grant, string $name, string $uri) {
    $user = routeAccessUser($role);

    if ($grant) {
        UserPermission::create(['user_id' => $user->id, 'permission' => $grant, 'effect' => 'grant']);
        User::clearOverrideCache($user->id);
    }

    $opens = $this->actingAs($user)->get($uri)->getStatusCode() === 200;

    expect(RouteAccess::allows($user, $name))->toBe($opens);
})->with([
    ['it', null, 'route-access.one', '/_route-access/one'],
    ['it', null, 'route-access.either', '/_route-access/either'],
    ['it', null, 'route-access.both', '/_route-access/both'],
    ['it', 'manage-branches', 'route-access.both', '/_route-access/both'],
    ['it', null, 'route-access.open', '/_route-access/open'],
    ['super_admin', null, 'route-access.both', '/_route-access/both'],
]);
