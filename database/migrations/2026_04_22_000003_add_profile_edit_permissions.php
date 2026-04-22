<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\RolePermission;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            RolePermission::firstOrCreate([
                'role'       => $role,
                'permission' => 'manage-profile-edits',
            ]);
        }
        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::where('permission', 'manage-profile-edits')->delete();
        RolePermission::clearCache();
    }
};
