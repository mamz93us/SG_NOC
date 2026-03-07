<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Grant view-network to super_admin, admin, and viewer
        // Grant manage-network-settings to super_admin and admin only
        $rows = [
            ['role' => 'super_admin', 'permission' => 'view-network',             'created_at' => $now, 'updated_at' => $now],
            ['role' => 'super_admin', 'permission' => 'manage-network-settings',  'created_at' => $now, 'updated_at' => $now],
            ['role' => 'super_admin', 'permission' => 'view-network-events',      'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin',       'permission' => 'view-network',             'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin',       'permission' => 'view-network-events',      'created_at' => $now, 'updated_at' => $now],
            ['role' => 'viewer',      'permission' => 'view-network',             'created_at' => $now, 'updated_at' => $now],
        ];

        // Use insertOrIgnore in case they somehow already exist
        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', ['view-network', 'manage-network-settings', 'view-network-events'])
            ->delete();
    }
};
