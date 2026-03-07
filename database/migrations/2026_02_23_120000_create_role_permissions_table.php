<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50);
            $table->string('permission', 100);
            $table->unique(['role', 'permission']);
            $table->timestamps();
        });

        // Seed default permissions
        $now = now();
        $rows = [];

        $all = [
            'view-branches', 'manage-branches',
            'view-contacts', 'manage-contacts', 'export-contacts',
            'view-activity-logs',
            'view-phone-logs', 'sync-phone-logs',
            'view-extensions', 'manage-extensions',
            'view-trunks',
            'manage-settings',
            'manage-users',
            'manage-permissions',
        ];

        $adminPerms  = array_diff($all, ['manage-users', 'manage-permissions']);
        $viewerPerms = [
            'view-branches', 'view-contacts',
            'view-activity-logs', 'view-phone-logs',
            'view-extensions', 'view-trunks',
        ];

        foreach ($all as $perm) {
            $rows[] = ['role' => 'super_admin', 'permission' => $perm, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($adminPerms as $perm) {
            $rows[] = ['role' => 'admin', 'permission' => $perm, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($viewerPerms as $perm) {
            $rows[] = ['role' => 'viewer', 'permission' => $perm, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('role_permissions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
