<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        // Grant reject-candidates to super_admin and admin roles. This is a
        // write into the live Teamtailor ATS, so it is gated separately from
        // the read-only view-candidates permission.
        foreach (['super_admin', 'admin'] as $role) {
            $rows[] = [
                'role' => $role,
                'permission' => 'reject-candidates',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'reject-candidates')
            ->delete();
    }
};
