<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every page that only `manage-settings` opened its own permission.
 *
 * Sender Addresses, Email Templates, Business App Accounts, Sync Status, the API
 * docs and keys, Ticket Portal Stats, Locations, Departments, Asset Types,
 * Internet Access Levels and Provisioning Licenses were all behind the one
 * "settings" permission, so a role could not be given Departments without also
 * being handed every integration credential on General Settings, and none of
 * those pages had a checkbox of its own. Branch add / edit / delete on the
 * Locations page now asks `manage-branches`, the permission the Branches page
 * already uses for the same actions.
 *
 * Nobody loses access: every role holding `manage-settings` is granted each new
 * slug, plus `manage-branches`, and a per-user `manage-settings` grant or deny is
 * copied onto each new slug with the same effect.
 *
 * Also removes `manage-vpn-settings`, left over from the strongSwan VPN Hub. No
 * route or view checks it since that was removed; its only effect was the
 * "unregistered permission" banner on the Roles and Permissions pages.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'manage-mail-senders',
        'manage-email-templates',
        'manage-business-apps',
        'manage-sync-status',
        'view-api-docs',
        'manage-hr-api-keys',
        'view-ticket-stats',
        'manage-locations',
        'manage-departments',
        'manage-asset-types',
        'manage-internet-access-levels',
        'manage-provisioning-licenses',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('role_permissions')) {
            return;
        }

        $now = now();

        $roles = DB::table('role_permissions')->where('permission', 'manage-settings')->pluck('role');

        foreach ($roles as $role) {
            foreach ([...self::PERMISSIONS, 'manage-branches'] as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('user_permissions')) {
            $overrides = DB::table('user_permissions')
                ->where('permission', 'manage-settings')
                ->get(['user_id', 'effect']);

            foreach ($overrides as $override) {
                foreach (self::PERMISSIONS as $permission) {
                    DB::table('user_permissions')->insertOrIgnore([
                        'user_id' => $override->user_id,
                        'permission' => $permission,
                        'effect' => $override->effect,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('user_permissions')->where('permission', 'manage-vpn-settings')->delete();
        }

        DB::table('role_permissions')->where('permission', 'manage-vpn-settings')->delete();
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::PERMISSIONS)->delete();

        if (Schema::hasTable('user_permissions')) {
            DB::table('user_permissions')->whereIn('permission', self::PERMISSIONS)->delete();
        }
    }
};
