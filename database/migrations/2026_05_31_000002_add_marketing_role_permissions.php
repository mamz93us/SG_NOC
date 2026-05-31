<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant the `marketing` role the permissions it needs to use the (now isolated)
 * marketing portal. Deliberately does NOT grant manage-email-marketing or
 * manage-email-marketing-settings — SES credentials, suppression lists and the
 * sender allowlist remain NOC-admin-only, per "admin control from NOC".
 *
 * `view-email-marketing` is the single gate the portal uses for full campaign /
 * subscriber / list / template management. Courses share the portal section, so
 * they get the same view/manage course grants other marketing users already get.
 */
return new class extends Migration
{
    private array $grants = [
        'view-email-marketing',
        'view-courses',
        'manage-courses',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->grants as $perm) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'marketing',
                'permission' => $perm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('role', 'marketing')
            ->whereIn('permission', $this->grants)
            ->delete();
    }
};
