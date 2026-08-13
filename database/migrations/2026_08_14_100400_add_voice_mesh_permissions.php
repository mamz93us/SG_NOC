<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Dedicated permissions rather than reusing view-network / manage-network-settings:
 * manage-voice-mesh grants the ability to set every branch's SIP password and to
 * rotate the ingest secret, which is a higher bar than editing a ping target.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['view-voice-mesh',   'super_admin'],
            ['view-voice-mesh',   'admin'],
            ['view-voice-mesh',   'viewer'],
            ['manage-voice-mesh', 'super_admin'],
            ['manage-voice-mesh', 'admin'],
        ];

        foreach ($permissions as [$permission, $role]) {
            RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission', ['view-voice-mesh', 'manage-voice-mesh'])->delete();
    }
};
