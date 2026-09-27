<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `take-exams`: sit the practice exams and see your own results.
 * `manage-exams`: the question bank (which holds the answers) and everyone's
 * results — super_admin only by default, because the people sitting the
 * exams are mostly admins and must not be able to read the answer key.
 *
 * Also declared in RolePermission::allPermissions() — seeding role_permissions
 * alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'take-exams' => ['super_admin', 'admin', 'viewer'],
            'manage-exams' => ['super_admin'],
        ];

        foreach ($grants as $permission => $roles) {
            foreach ($roles as $role) {
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
        DB::table('role_permissions')->whereIn('permission', ['take-exams', 'manage-exams'])->delete();
    }
};
