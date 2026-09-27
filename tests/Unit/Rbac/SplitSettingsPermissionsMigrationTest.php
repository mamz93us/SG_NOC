<?php

use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * The migration that gave the settings sub-pages their own permissions must not
 * take a page away from anyone: whoever held manage-settings before holds each
 * new permission after, with the same effect for a per-user grant or deny.
 */
uses(Tests\TestCase::class);

beforeEach(fn () => RbacTestSchema::create());

afterEach(fn () => RbacTestSchema::drop());

function splitSettingsMigration(): object
{
    return require database_path('migrations/2026_09_16_400001_split_settings_page_permissions.php');
}

/** @return array<int, string> */
function splitSettingsSlugs(): array
{
    return (new ReflectionClassConstant(splitSettingsMigration(), 'PERMISSIONS'))->getValue();
}

it('grants only permissions that have a checkbox', function () {
    expect(array_diff(splitSettingsSlugs(), RolePermission::allSlugs()))->toBe([]);
});

it('gives each role that held manage-settings every new permission', function () {
    $now = now();
    DB::table('role_permissions')->insert([
        ['role' => 'admin', 'permission' => 'manage-settings', 'created_at' => $now, 'updated_at' => $now],
        ['role' => 'admin', 'permission' => 'manage-branches', 'created_at' => $now, 'updated_at' => $now],
        ['role' => 'it', 'permission' => 'view-branches', 'created_at' => $now, 'updated_at' => $now],
    ]);

    splitSettingsMigration()->up();

    $admin = DB::table('role_permissions')->where('role', 'admin')->pluck('permission')->all();
    expect(array_diff([...splitSettingsSlugs(), 'manage-branches', 'manage-settings'], $admin))->toBe([]);
    expect(count($admin))->toBe(count(array_unique($admin)));

    // A role that never held manage-settings gains nothing.
    expect(DB::table('role_permissions')->where('role', 'it')->pluck('permission')->all())->toBe(['view-branches']);
});

it('copies a per-user grant or deny of manage-settings onto each new permission', function () {
    $now = now();
    DB::table('user_permissions')->insert([
        ['user_id' => 1, 'permission' => 'manage-settings', 'effect' => 'grant', 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => 2, 'permission' => 'manage-settings', 'effect' => 'deny', 'created_at' => $now, 'updated_at' => $now],
    ]);

    splitSettingsMigration()->up();

    foreach (splitSettingsSlugs() as $slug) {
        expect(DB::table('user_permissions')->where('user_id', 1)->where('permission', $slug)->value('effect'))->toBe('grant');
        expect(DB::table('user_permissions')->where('user_id', 2)->where('permission', $slug)->value('effect'))->toBe('deny');
    }
});

it('removes the grants for the retired VPN Hub permission', function () {
    $now = now();
    DB::table('role_permissions')->insert(['role' => 'super_admin', 'permission' => 'manage-vpn-settings', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('user_permissions')->insert(['user_id' => 1, 'permission' => 'manage-vpn-settings', 'effect' => 'grant', 'created_at' => $now, 'updated_at' => $now]);

    splitSettingsMigration()->up();

    expect(DB::table('role_permissions')->where('permission', 'manage-vpn-settings')->count())->toBe(0);
    expect(DB::table('user_permissions')->where('permission', 'manage-vpn-settings')->count())->toBe(0);
    expect(RolePermission::unregisteredSlugs())->toBe([]);
});
