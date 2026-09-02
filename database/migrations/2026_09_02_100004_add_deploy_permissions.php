<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Deployment server permissions.
 *
 * Seeding the table is only half the job — the same slugs must also appear in
 * RolePermission::allPermissions(), because AppServiceProvider only calls
 * Gate::define() for slugs in that array. Without it @can() returns false for
 * every non-super_admin and the whole UI is invisible to the people it is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['view-deploy-servers', 'super_admin'],
            ['view-deploy-servers', 'admin'],
            ['view-deploy-servers', 'viewer'],
            ['run-deploy-commands', 'super_admin'],
            ['run-deploy-commands', 'admin'],
            // manage- stays super_admin only: it stores private keys and defines
            // arbitrary remote shell. Also excluded from the admin defaults in
            // RolePermission::defaultPermissions().
            ['manage-deploy-servers', 'super_admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', [
            'view-deploy-servers', 'run-deploy-commands', 'manage-deploy-servers',
        ])->delete();
    }
};
