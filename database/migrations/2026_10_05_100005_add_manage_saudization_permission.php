<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `manage-saudization`: edit the Saudization groups and the percentage each
 * one needs (Attendance ▸ Saudization ▸ Edit targets). Its own slug because a
 * changed percentage changes which groups the page — and the assistant — call
 * compliant.
 *
 * Seeded to the roles that already hold view-attendance and view-vacations,
 * the two permissions that open the page, so it shows nobody anything new.
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
                'permission' => 'manage-saudization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'manage-saudization')->delete();
    }
};
