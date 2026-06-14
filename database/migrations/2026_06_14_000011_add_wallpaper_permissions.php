<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wallpaper manager access. `view-wallpapers` = see the page + the deployment
 * links; `manage-wallpapers` = add domains / upload images / delete. Granted to
 * super_admin and admin, matching the existing view/manage convention.
 *
 * NOTE: the public manifest + script endpoints are intentionally NOT gated by
 * these permissions — Intune devices fetch them unauthenticated.
 */
return new class extends Migration
{
    private array $newPermissions = [
        'view-wallpapers',
        'manage-wallpapers',
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
