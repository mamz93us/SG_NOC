<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $allPermissions = [
        'view-server-status',
        'manage-server-status',
    ];

    private array $viewOnlyPermissions = [
        'view-server-status',
    ];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->allPermissions as $perm) {
                RolePermission::firstOrCreate(['role' => $role, 'permission' => $perm]);
            }
        }

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
