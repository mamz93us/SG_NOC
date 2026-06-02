<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions for the GDMS phone-management module.
 *   view-phones   — read the phone inventory + detail pages
 *   manage-phones — add to GDMS, assign accounts/employees, reboot, push config/templates
 *   reset-phones  — factory reset (destructive) — super_admin only
 */
return new class extends Migration
{
    public function up(): void
    {
        $now  = now();
        $rows = [];

        $permissions = [
            'view-phones'   => ['super_admin', 'admin', 'viewer'],
            'manage-phones' => ['super_admin', 'admin'],
            'reset-phones'  => ['super_admin'],
        ];

        foreach ($permissions as $perm => $roles) {
            foreach ($roles as $role) {
                $rows[] = [
                    'role'       => $role,
                    'permission' => $perm,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', ['view-phones', 'manage-phones', 'reset-phones'])
            ->delete();
    }
};
