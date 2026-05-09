<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['role' => 'super_admin', 'permission' => 'manage-forms', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin',       'permission' => 'manage-forms', 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'manage-forms')
            ->delete();
    }
};
