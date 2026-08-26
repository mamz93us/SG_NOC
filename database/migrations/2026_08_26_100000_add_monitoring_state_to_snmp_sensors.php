<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a sensor be retired or muted without destroying it.
 *
 * Two problems, one shape:
 *
 *  1. Sophos VPN sensors were created on discovery and never removed, so a
 *     tunnel deleted from the firewall showed on the NOC dashboard forever.
 *     Deleting the sensor row would fix the display but cascade away its whole
 *     metric history (sensor_metrics and metric_rollups both cascade), and a
 *     single failed SNMP walk would then be unrecoverable data loss.
 *
 *  2. Tunnels that are deliberately disabled -- a backup link kept off until
 *     it is needed -- were rendered as red "down", indistinguishable from a
 *     tunnel that had actually failed.
 *
 * `retired_at` is set by discovery when a sensor stops being reported and
 * cleared the moment it reappears. `monitor_enabled` is the operator's own
 * switch and discovery never touches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->boolean('monitor_enabled')->default(true)->after('graph_enabled');
            $table->timestamp('retired_at')->nullable()->after('last_recorded_at');
            $table->index(['sensor_group', 'retired_at'], 'snmp_sensors_group_retired_idx');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->dropIndex('snmp_sensors_group_retired_idx');
            $table->dropColumn(['monitor_enabled', 'retired_at']);
        });
    }
};
