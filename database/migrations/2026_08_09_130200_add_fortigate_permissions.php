<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newPermissions = [
        'view-fortigate',
        'manage-fortigate',
    ];

    public function up(): void
    {
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

        DB::table('role_permissions')->insertOrIgnore([
            'role'       => 'viewer',
            'permission' => 'view-fortigate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
