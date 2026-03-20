<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── monitored_hosts indexes ──
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->index('ip', 'monitored_hosts_ip_index');
            $table->index('branch_id', 'monitored_hosts_branch_id_index');
            $table->index('status', 'monitored_hosts_status_index');
            $table->index(['snmp_enabled', 'status'], 'monitored_hosts_snmp_enabled_status_index');
        });

        // ── sensor_metrics indexes ──
        // Composite [sensor_id, recorded_at] already exists from rebuild migration.
        Schema::table('sensor_metrics', function (Blueprint $table) {
            $table->index('sensor_id', 'sensor_metrics_sensor_id_index');
            $table->index('recorded_at', 'sensor_metrics_recorded_at_index');
        });

        // ── noc_events indexes ──
        // Single indexes on module, status, severity already exist from create migration.
        Schema::table('noc_events', function (Blueprint $table) {
            $table->index('source_id', 'noc_events_source_id_index');
            $table->index('first_seen', 'noc_events_first_seen_index');
            $table->index(['module', 'status'], 'noc_events_module_status_index');
        });

        // ── vpn_tunnels indexes ──
        Schema::table('vpn_tunnels', function (Blueprint $table) {
            $table->index('status', 'vpn_tunnels_status_index');
            $table->index('branch_id', 'vpn_tunnels_branch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropIndex('monitored_hosts_ip_index');
            $table->dropIndex('monitored_hosts_branch_id_index');
            $table->dropIndex('monitored_hosts_status_index');
            $table->dropIndex('monitored_hosts_snmp_enabled_status_index');
        });

        Schema::table('sensor_metrics', function (Blueprint $table) {
            $table->dropIndex('sensor_metrics_sensor_id_index');
            $table->dropIndex('sensor_metrics_recorded_at_index');
        });

        Schema::table('noc_events', function (Blueprint $table) {
            $table->dropIndex('noc_events_source_id_index');
            $table->dropIndex('noc_events_first_seen_index');
            $table->dropIndex('noc_events_module_status_index');
        });

        Schema::table('vpn_tunnels', function (Blueprint $table) {
            $table->dropIndex('vpn_tunnels_status_index');
            $table->dropIndex('vpn_tunnels_branch_id_index');
        });
    }
};
