<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['manage-radius', 'super_admin'],
            ['manage-radius', 'admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::where('permission', 'manage-radius')->delete();
    }
};
