<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `create-tickets` = use the Create Ticket form (raise for yourself).
 * `create-tickets-for-others` = pick a different requester on that form.
 * `view-tickets` = see the submission history page.
 *
 * Viewers get create+view so ordinary staff can raise their own tickets from
 * the NOC without being granted anything else.
 */
return new class extends Migration
{
    private array $adminPermissions = [
        'create-tickets',
        'create-tickets-for-others',
        'view-tickets',
    ];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->adminPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (['viewer', 'hr'] as $role) {
            foreach (['create-tickets', 'view-tickets'] as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->adminPermissions)->delete();
    }
};
