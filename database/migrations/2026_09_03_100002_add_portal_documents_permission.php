<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Authoring permission for the employee document library.
 *
 * Reading needs no permission — every signed-in employee sees the portal, which
 * is the point. This gates who can publish an IT policy to the whole company.
 *
 * The slug must ALSO be listed in RolePermission::allPermissions(), or the Gate
 * is never defined and @can returns false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            RolePermission::firstOrCreate([
                'role' => $role,
                'permission' => 'manage-portal-documents',
            ]);
        }
    }

    public function down(): void
    {
        RolePermission::where('permission', 'manage-portal-documents')->delete();
    }
};
