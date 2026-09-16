<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `use-archive-portal`: sign in to the document archive at archive.samirgroup.net.
 * `use-archive-ai`: Ask the archive, Ask about a document, and the archive tools
 * in the Samir AI Assistant.
 * `manage-archive-portal`: archives, fields, members, the ArcMate source, the
 * Transfer page, AI batches and budget.
 *
 * Seeded to super_admin ONLY, deliberately. These archives hold every supplier
 * invoice, HR file and contract the company has scanned since 2013, and holding
 * the portal permission is not the same as reaching a document: access is per
 * archive (archive_members). Granting it broadly by role would put the whole
 * finance history one click from anyone who already administers the NOC.
 *
 * Grant it to named people in Admin ▸ Users ▸ Permissions (and `use-archive-ai`
 * from AI ▸ AI Access), then add them to the archives they work with.
 *
 * Also declared in RolePermission::allPermissions() — seeding role_permissions
 * alone leaves @can false for every non-super_admin.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'use-archive-portal',
        'use-archive-ai',
        'manage-archive-portal',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'super_admin',
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::PERMISSIONS)->delete();
    }
};
