<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attendance pages. `view-attendance` shows everyone's check-in/out times;
 * `manage-attendance` also edits BioTime connections and employee links.
 *
 * The slugs are declared in RolePermission::allPermissions() as well — seeding
 * role_permissions alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    private const GRANTS = [
        'super_admin' => ['view-attendance', 'manage-attendance'],
        'admin' => ['view-attendance', 'manage-attendance'],
        'hr' => ['view-attendance', 'manage-attendance'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            foreach ($permissions as $permission) {
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
        DB::table('role_permissions')->whereIn('permission', ['view-attendance', 'manage-attendance'])->delete();
    }
};
