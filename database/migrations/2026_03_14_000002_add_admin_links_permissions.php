<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\RolePermission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'super_admin' => ['view-admin-links', 'manage-admin-links'],
            'admin'       => ['view-admin-links', 'manage-admin-links'],
            'viewer'      => ['view-admin-links'],
        ];

        foreach ($permissions as $role => $perms) {
            foreach ($perms as $perm) {
                RolePermission::firstOrCreate(['role' => $role, 'permission' => $perm]);
            }
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', ['view-admin-links', 'manage-admin-links'])->delete();
    }
};
