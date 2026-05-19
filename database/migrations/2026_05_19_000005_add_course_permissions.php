<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newPermissions = [
        'view-courses',
        'manage-courses',
    ];

    public function up(): void
    {
        $now = now();

        // Sysadmin roles get full access.
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->newPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role'       => $role,
                    'permission' => $perm,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Anyone allowed to use the marketing portal also gets course access — the
        // course UI lives in the same portal section and shares the SES pipeline.
        $marketingRoles = DB::table('role_permissions')
            ->where('permission', 'manage-email-marketing')
            ->pluck('role')
            ->unique();

        foreach ($marketingRoles as $role) {
            foreach ($this->newPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role'       => $role,
                    'permission' => $perm,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
