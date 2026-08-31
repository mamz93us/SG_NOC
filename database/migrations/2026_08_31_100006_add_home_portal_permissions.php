<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Announcement and greeting authoring permissions.
 *
 * The home portal itself needs no permission — every authenticated employee
 * sees it, which is the whole point. These gate who can WRITE what the company
 * reads every morning.
 */
return new class extends Migration
{
    private array $permissions = [
        'view-announcements',
        'manage-announcements',
        'manage-greeting-lines',
    ];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->permissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // HR posts most of what goes on a company noticeboard.
        foreach (['view-announcements', 'manage-announcements'] as $perm) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'hr',
                'permission' => $perm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->permissions)->delete();
    }
};
