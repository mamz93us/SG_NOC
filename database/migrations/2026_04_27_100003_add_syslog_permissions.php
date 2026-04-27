<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['view-syslog',   'super_admin'],
            ['view-syslog',   'admin'],
            ['view-syslog',   'viewer'],
            ['manage-syslog', 'super_admin'],
            ['manage-syslog', 'admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', ['view-syslog', 'manage-syslog'])->delete();
    }
};
