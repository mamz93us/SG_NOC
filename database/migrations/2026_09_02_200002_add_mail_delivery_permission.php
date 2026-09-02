<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * SES mail-delivery log permission.
 *
 * Note the slug is `view-mail-delivery`, NOT `view-email-log`: the platform
 * already has `view-email-logs` for the app's own notification send log, and two
 * slugs differing by one character would be a trap.
 *
 * Deliberately NOT given to `viewer`: the log carries the subject line and
 * recipient of every message the account sends, which includes HR onboarding
 * and offboarding mail. Seeing that is a narrower thing than "read-only NOC".
 *
 * Remember the slug must also be in RolePermission::allPermissions(), or the
 * Gate is never defined and @can returns false for every non-super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['view-mail-delivery', 'super_admin'],
            ['view-mail-delivery', 'admin'],
        ] as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::where('permission', 'view-mail-delivery')->delete();
    }
};
