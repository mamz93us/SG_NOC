<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Re-seed the browser_user → view-browser-portal row in case it was
        // wiped by the prior PermissionsController.update() bug (its $roles
        // array did not include browser_user, so a truncate+reinsert cycle
        // would silently remove it).
        RolePermission::firstOrCreate([
            'role'       => 'browser_user',
            'permission' => 'view-browser-portal',
        ]);

        RolePermission::clearCache();
    }

    public function down(): void
    {
        // Intentionally no-op: removing this would break portal access.
    }
};
