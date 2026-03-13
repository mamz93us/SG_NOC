<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\RolePermission;

return new class extends Migration
{
    private array $allPermissions = [
        'view-itam',
        'manage-itam',
        'manage-suppliers',
        'view-licenses',
        'manage-licenses',
        'view-accessories',
        'manage-accessories',
    ];

    private array $viewOnlyPermissions = [
        'view-itam',
        'view-licenses',
        'view-accessories',
    ];

    public function up(): void
    {
        // Grant all ITAM permissions to super_admin and admin
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->allPermissions as $perm) {
                RolePermission::firstOrCreate(['role' => $role, 'permission' => $perm]);
            }
        }

        // Grant view-only ITAM permissions to viewer
        foreach ($this->viewOnlyPermissions as $perm) {
            RolePermission::firstOrCreate(['role' => 'viewer', 'permission' => $perm]);
        }

        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', $this->allPermissions)->delete();
        RolePermission::clearCache();
    }
};
