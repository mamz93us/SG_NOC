<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `manage-attendance-owners`: add, change and remove people on the attendance
 * owner list (Attendance ▸ Owners). Its own slug because a row there lets
 * someone read a branch's or the whole company's attendance from the
 * home-portal assistant.
 *
 * Seeded to the roles that already hold view-attendance — everyone's
 * attendance on the admin pages — so it widens nobody's own reach.
 *
 * Also declared in RolePermission::allPermissions() — seeding role_permissions
 * alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin', 'hr'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'permission' => 'manage-attendance-owners',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'manage-attendance-owners')->delete();
    }
};
