<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Seed the new share-browser-session permission onto super_admin + admin.
        // (browser_user role gets only view-browser-portal; no manage, no share.)
        foreach (['super_admin', 'admin'] as $role) {
            foreach (['share-browser-session'] as $permission) {
                RolePermission::firstOrCreate([
                    'role'       => $role,
                    'permission' => $permission,
                ]);
            }
        }

        // Seed the browser_user role with only the view permission.
        RolePermission::firstOrCreate([
            'role'       => 'browser_user',
            'permission' => 'view-browser-portal',
        ]);

        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::where('permission', 'share-browser-session')->delete();
        RolePermission::where('role', 'browser_user')->delete();
        RolePermission::clearCache();
    }
};
