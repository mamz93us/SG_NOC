<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * HR workspace permissions. `manage-hr-portal` gates the workspace itself;
     * the two submit-* slugs gate the individual request forms so a narrow HR
     * user can be given onboarding only, termination only, etc.
     */
    public function up(): void
    {
        $permissions = [
            ['manage-hr-portal',           'super_admin'],
            ['manage-hr-portal',           'admin'],
            ['manage-hr-portal',           'hr'],
            ['submit-hr-offboarding',      'super_admin'],
            ['submit-hr-offboarding',      'admin'],
            ['submit-hr-offboarding',      'hr'],
            ['submit-hr-employee-update',  'super_admin'],
            ['submit-hr-employee-update',  'admin'],
            ['submit-hr-employee-update',  'hr'],
            // Existing slug — make sure admins have it too, not just `hr`.
            ['submit-hr-onboarding',       'super_admin'],
            ['submit-hr-onboarding',       'admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(
                ['role' => $role, 'permission' => $permission]
            );
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', [
            'manage-hr-portal',
            'submit-hr-offboarding',
            'submit-hr-employee-update',
        ])->delete();
    }
};
