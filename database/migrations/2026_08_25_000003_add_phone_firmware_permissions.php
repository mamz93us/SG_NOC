<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firmware server access. `view-phone-firmware` = see the library + the status
 * board; `manage-phone-firmware` = upload / fetch-from-URL / publish / delete.
 * Same view/manage convention as the Download Center and GDMS phone permissions.
 */
return new class extends Migration
{
    private array $newPermissions = [
        'view-phone-firmware',
        'manage-phone-firmware',
    ];

    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            foreach ($this->newPermissions as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Viewers already see the phone inventory; let them see firmware state too.
        DB::table('role_permissions')->insertOrIgnore([
            'role' => 'viewer',
            'permission' => 'view-phone-firmware',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', $this->newPermissions)->delete();
    }
};
