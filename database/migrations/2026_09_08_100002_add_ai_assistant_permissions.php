<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * The slugs must ALSO be listed in RolePermission::allPermissions(), or the
 * Gate is never defined and @can returns false for every non-super_admin —
 * see PermissionsController::update(), which truncates and re-inserts only
 * allSlugs() on every save.
 */
return new class extends Migration
{
    private array $permissions = ['manage-ai-assistant', 'view-ai-conversations'];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->permissions as $perm) {
                RolePermission::firstOrCreate(['role' => $role, 'permission' => $perm]);
            }
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', $this->permissions)->delete();
    }
};
