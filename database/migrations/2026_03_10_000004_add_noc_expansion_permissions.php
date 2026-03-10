<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\RolePermission;

return new class extends Migration
{
    private array $newPermissions = [
        'view-incidents',
        'manage-incidents',
    ];

    public function up(): void
    {
        // Grant all new permissions to super_admin
        foreach ($this->newPermissions as $perm) {
            RolePermission::firstOrCreate(['role' => 'super_admin', 'permission' => $perm]);
        }

        // Grant to admin role too
        foreach ($this->newPermissions as $perm) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission' => $perm]);
        }

        // Give viewer read-only
        RolePermission::firstOrCreate(['role' => 'viewer', 'permission' => 'view-incidents']);

        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', $this->newPermissions)->delete();
        RolePermission::clearCache();
    }
};
