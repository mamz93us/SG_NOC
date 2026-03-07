<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $rows = [
            ['role' => 'admin', 'permission' => 'manage-network-settings', 'created_at' => $now, 'updated_at' => $now],
        ];

        \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role', 'admin')
            ->where('permission', 'manage-network-settings')
            ->delete();
    }
};
