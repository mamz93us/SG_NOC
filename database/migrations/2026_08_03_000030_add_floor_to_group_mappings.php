<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds floor as a fourth optional dimension to the auto-provisioning group
 * mappings, so groups (printer groups in particular) can be targeted at the
 * people sitting on a specific floor.
 *
 * NULL means "any", like the other three — existing mappings are unaffected.
 *
 * Timing note: unlike branch/department/gender, the floor is not known when HR
 * submits. The manager picks it on the setup form, so floor-specific mappings
 * can only be applied in the second provisioning stage. See
 * UserProvisioningService::completeProvisioning().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_department_group_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('floor_id')->nullable()->after('department_id');
            $table->foreign('floor_id')->references('id')->on('network_floors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_department_group_mappings', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropColumn('floor_id');
        });
    }
};
