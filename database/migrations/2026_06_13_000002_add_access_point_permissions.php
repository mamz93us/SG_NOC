<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newPermissions = [
        'view-access-points',
        'manage-access-points',
    ];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->newPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Viewers get read-only access
        DB::table('role_permissions')->insertOrIgnore([
            'role' => 'viewer',
            'permission' => 'view-access-points',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
