<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds intune_managed_device_id to azure_devices.
 *
 * WHY:
 *   azure_device_id  = Azure AD hardware/registration ID  (from /devices or azureADDeviceId)
 *   intune_managed_device_id = Intune MDM enrollment ID  (from /deviceManagement/managedDevices[].id)
 *
 * These are two different GUIDs for the same physical device.
 * Intune script run states use the MDM enrollment ID, so we need
 * this column to match script results back to the correct azure_devices row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->string('intune_managed_device_id')->nullable()->unique()->after('azure_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->dropColumn('intune_managed_device_id');
        });
    }
};
