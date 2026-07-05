<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the Access-Gateway permissions onto the default roles. Mirrors
     * add_phase2_permissions: super_admin + admin get full management,
     * viewers get read-only audit access. Individual grants can still be
     * tuned per-role from Admin → Role Permissions.
     */
    public function up(): void
    {
        $grants = [
            ['super_admin', 'manage-agw-settings'],
            ['super_admin', 'manage-agw-allowlist'],
            ['super_admin', 'view-agw-audit'],

            ['admin', 'manage-agw-settings'],
            ['admin', 'manage-agw-allowlist'],
            ['admin', 'view-agw-audit'],

            ['viewer', 'view-agw-audit'],
        ];

        foreach ($grants as [$role, $permission]) {
            RolePermission::firstOrCreate(compact('role', 'permission'));
        }

        RolePermission::clearCache();
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', [
            'manage-agw-settings',
            'manage-agw-allowlist',
            'view-agw-audit',
        ])->delete();

        RolePermission::clearCache();
    }
};
