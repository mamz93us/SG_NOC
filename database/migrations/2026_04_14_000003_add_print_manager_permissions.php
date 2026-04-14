<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['role' => 'super_admin', 'permission' => 'view-print-manager',   'created_at' => $now, 'updated_at' => $now],
            ['role' => 'super_admin', 'permission' => 'manage-print-manager', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin',       'permission' => 'view-print-manager',   'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin',       'permission' => 'manage-print-manager', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'viewer',      'permission' => 'view-print-manager',   'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', ['view-print-manager', 'manage-print-manager'])
            ->delete();
    }
};
