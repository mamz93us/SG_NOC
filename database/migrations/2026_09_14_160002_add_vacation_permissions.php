<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `view-vacations`: every employee's Oracle leave balance and leave records.
 * `manage-vacations`: import the Oracle sheets and decide which employee an
 * Oracle number is.
 *
 * Seeded to the roles that already see and manage attendance, the nearest
 * existing reach over the same people.
 *
 * Also declared in RolePermission::allPermissions() — seeding role_permissions
 * alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin', 'hr'] as $role) {
            foreach (['view-vacations', 'manage-vacations'] as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['view-vacations', 'manage-vacations'])->delete();
    }
};
