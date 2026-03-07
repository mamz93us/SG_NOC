<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['view-workflows',    'super_admin'],
            ['view-workflows',    'admin'],
            ['view-workflows',    'viewer'],
            ['manage-workflows',  'super_admin'],
            ['manage-workflows',  'admin'],
            ['approve-workflows', 'super_admin'],
            ['approve-workflows', 'admin'],
            ['view-employees',    'super_admin'],
            ['view-employees',    'admin'],
            ['view-employees',    'viewer'],
            ['manage-employees',  'super_admin'],
            ['manage-employees',  'admin'],
            ['view-noc',          'super_admin'],
            ['view-noc',          'admin'],
            ['manage-noc',        'super_admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(
                ['role' => $role, 'permission' => $permission]
            );
        }
    }

    public function down(): void
    {
        $slugs = [
            'view-workflows', 'manage-workflows', 'approve-workflows',
            'view-employees', 'manage-employees',
            'view-noc', 'manage-noc',
        ];

        RolePermission::whereIn('permission', $slugs)->delete();
    }
};
