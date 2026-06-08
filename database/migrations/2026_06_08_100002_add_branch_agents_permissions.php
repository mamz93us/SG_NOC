<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['view-branch-agents',   'super_admin'],
            ['view-branch-agents',   'admin'],
            ['view-branch-agents',   'viewer'],
            ['manage-branch-agents', 'super_admin'],
            ['manage-branch-agents', 'admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', ['view-branch-agents', 'manage-branch-agents'])->delete();
    }
};
