<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now  = now();
        $rows = [];

        $permissions = [
            // Assets / Devices
            'view-assets'    => ['super_admin', 'admin', 'viewer'],
            'manage-assets'  => ['super_admin', 'admin'],
            // Credentials
            'view-credentials'   => ['super_admin', 'admin'],
            'manage-credentials' => ['super_admin'],
            // Printers
            'view-printers'   => ['super_admin', 'admin', 'viewer'],
            'manage-printers' => ['super_admin', 'admin'],
            // Identity
            'view-identity'           => ['super_admin', 'admin'],
            'manage-identity'         => ['super_admin', 'admin'],
            'manage-identity-settings'=> ['super_admin'],
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
            ->whereIn('permission', [
                'view-assets', 'manage-assets',
                'view-credentials', 'manage-credentials',
                'view-printers', 'manage-printers',
                'view-identity', 'manage-identity', 'manage-identity-settings',
            ])->delete();
    }
};
