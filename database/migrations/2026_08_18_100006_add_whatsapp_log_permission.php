<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => 'view-whatsapp-logs']);
        }
    }

    public function down(): void
    {
        RolePermission::where('permission', 'view-whatsapp-logs')->delete();
    }
};
