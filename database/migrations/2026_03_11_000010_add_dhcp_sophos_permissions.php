<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newPermissions = [
        'view-dhcp-leases',
        'view-sophos',
        'manage-sophos',
    ];

    public function up(): void
    {
        // Grant new permissions to super_admin and admin roles
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->newPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role'       => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Also grant view permissions to viewer role
        foreach (['view-dhcp-leases', 'view-sophos'] as $perm) {
            DB::table('role_permissions')->insertOrIgnore([
                'role'       => 'viewer',
                'permission' => $perm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
