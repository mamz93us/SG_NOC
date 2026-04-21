<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\RolePermission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'super_admin' => ['view-browser-portal', 'manage-browser-portal'],
            'admin'       => ['view-browser-portal', 'manage-browser-portal'],
            'viewer'      => [],
        ];

        foreach ($permissions as $role => $perms) {
            foreach ($perms as $perm) {
                RolePermission::firstOrCreate(['role' => $role, 'permission' => $perm]);
            }
        }

        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', ['view-browser-portal', 'manage-browser-portal'])->delete();
        RolePermission::clearCache();
    }
};
