<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default VLAN per branch — drives the Tunnel-Private-Group-Id attribute
 * returned in Access-Accept.
 *
 * Resolution order (FreeRADIUS authorize_reply_query — also mirrored by
 * App\Services\RadiusVlanPolicyResolver):
 *   1. radius_mac_overrides.vlan_override (per-MAC override)
 *   2. Most-specific branch policy row (lowest priority wins on ties):
 *        exact adapter_type + exact device_type
 *        exact adapter_type + NULL  device_type
 *        'any'              + exact device_type
 *        'any'              + NULL  device_type   (catch-all per branch)
 *   3. Otherwise: no VLAN attrs returned → switch falls back to its own
 *      default VLAN (rollout-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_branch_vlan_policy', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')
                  ->references('id')->on('branches')
                  ->cascadeOnDelete();

            // Match against device_macs.adapter_type. 'any' = catch-all.
            $table->enum('adapter_type', [
                'ethernet',
                'wifi',
                'usb_ethernet',
                'management',
                'virtual',
                'any',
            ])->default('any');

            // Optional secondary discriminator — matches devices.type
            // (phone|printer|switch|ap|pc|...). NULL = any device type.
            $table->string('device_type', 32)->nullable();

            // The VLAN ID returned in Tunnel-Private-Group-Id.
            $table->unsignedSmallInteger('vlan_id');

            // Lower wins on ties. Default 100 leaves room for explicit overrides.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->string('description', 255)->nullable();
            $table->timestamps();

            // One row per (branch, adapter_type, device_type) combination.
            $table->unique(['branch_id', 'adapter_type', 'device_type'], 'rad_vlan_branch_adapter_devtype_unique');

            $table->index('branch_id');
            $table->index(['branch_id', 'adapter_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_branch_vlan_policy');
    }
};
