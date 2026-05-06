<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-MAC RADIUS allow/deny + VLAN override.
 *
 * Kept as a separate, opt-in table (rather than columns on device_macs) so
 * automated syncs (Intune, SNMP, DHCP) can never clobber a manual decision
 * an admin made on the registry page.
 *
 * Semantics:
 *   - radius_enabled=false  → reject regardless of device_macs.is_active.
 *   - vlan_override is non-null → wins over branch VLAN policy.
 *   - row absent              → fall through to device_macs.is_active and
 *                                 branch VLAN policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_mac_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_mac_id')
                  ->unique()
                  ->constrained('device_macs')
                  ->cascadeOnDelete();

            $table->boolean('radius_enabled')->default(true);

            $table->unsignedSmallInteger('vlan_override')->nullable();

            $table->string('notes', 255)->nullable();

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index('radius_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_mac_overrides');
    }
};
