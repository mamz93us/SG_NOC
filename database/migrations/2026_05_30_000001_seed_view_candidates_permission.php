<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        // Grant view-candidates to super_admin and admin roles
        foreach (['super_admin', 'admin'] as $role) {
            $rows[] = [
                'role' => $role,
                'permission' => 'view-candidates',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'view-candidates')
            ->delete();
    }
};
