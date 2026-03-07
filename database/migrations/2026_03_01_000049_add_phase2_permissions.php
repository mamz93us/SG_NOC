<?php
use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['manage-workflow-templates', 'super_admin'],
            ['manage-workflow-templates', 'admin'],
            ['view-email-logs',           'super_admin'],
            ['view-email-logs',           'admin'],
            ['manage-notification-rules', 'super_admin'],
            ['manage-notification-rules', 'admin'],
            ['manage-license-monitors',   'super_admin'],
            ['manage-license-monitors',   'admin'],
            ['manage-allowed-domains',    'super_admin'],
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
            'manage-workflow-templates', 'view-email-logs',
            'manage-notification-rules', 'manage-license-monitors',
            'manage-allowed-domains',
        ])->delete();
    }
};
