<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Access to the Security Awareness roster.
 *
 * Its own permission rather than riding on `manage-settings`: the page shows
 * every colleague's risk score and phishing history, and someone who can edit
 * settings does not automatically need that. The employee portal is unaffected
 * — people always see their own score there and only their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'permission' => 'view-knowbe4-scores',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'view-knowbe4-scores')->delete();
    }
};
