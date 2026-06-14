<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Download Center access. `view-downloads` = see the list + download through the
 * NOC; `manage-downloads` = upload / fetch-from-URL / share / delete. Granted to
 * super_admin and admin, matching the existing view/manage permission convention.
 */
return new class extends Migration
{
    private array $newPermissions = [
        'view-downloads',
        'manage-downloads',
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
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
