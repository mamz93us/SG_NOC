<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Monitored Hosts Updates
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->boolean('ping_enabled')->default(true)->after('type');
            $table->integer('ping_interval_seconds')->default(60)->after('ping_enabled');
            $table->integer('snmp_port')->default(161)->after('snmp_community');
            $table->timestamp('last_ping_at')->nullable()->after('status');
            $table->timestamp('last_snmp_at')->nullable()->after('last_ping_at');
        });

        // Modifying ENUMs using DB statement since we are adding states
        DB::statement("ALTER TABLE monitored_hosts MODIFY COLUMN status ENUM('up', 'down', 'degraded', 'unknown') DEFAULT 'unknown'");

        // 2. Rename & Modify network_checks -> host_checks
        Schema::rename('network_checks', 'host_checks');
        Schema::table('host_checks', function (Blueprint $table) {
            $table->renameColumn('latency', 'latency_ms');
            $table->string('check_type')->default('ping')->after('host_id');
        });

        // 3. SNMP Sensors Updates
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->string('name')->nullable()->after('host_id');
            $table->string('data_type')->default('gauge')->after('description');
            $table->string('unit')->nullable()->after('data_type');
            $table->integer('poll_interval')->default(60)->after('unit');
            $table->float('warning_threshold')->nullable()->after('poll_interval');
            $table->float('critical_threshold')->nullable()->after('warning_threshold');
        });

        // Fill existing sensors with name = description
        DB::statement("UPDATE snmp_sensors SET name = description WHERE name IS NULL");

        // 4. Metrics -> Sensor Metrics Rebuild
        Schema::dropIfExists('metrics');

        Schema::create('sensor_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_id')->constrained('snmp_sensors')->onDelete('cascade');
            $table->float('value');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['sensor_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        // Drop new table, recreate metrics
        Schema::dropIfExists('sensor_metrics');
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('monitored_hosts')->onDelete('cascade');
            $table->string('metric_name');
            $table->float('value');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
            $table->index(['host_id', 'metric_name', 'recorded_at']);
        });

        // snmp_sensors rollback
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->dropColumn(['name', 'data_type', 'unit', 'poll_interval', 'warning_threshold', 'critical_threshold']);
        });

        // host_checks rollback
        Schema::table('host_checks', function (Blueprint $table) {
            $table->renameColumn('latency_ms', 'latency');
            $table->dropColumn('check_type');
        });
        Schema::rename('host_checks', 'network_checks');

        // monitored_hosts rollback
        DB::statement("ALTER TABLE monitored_hosts MODIFY COLUMN status ENUM('up', 'down') DEFAULT 'up'");
        
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropColumn(['ping_enabled', 'ping_interval_seconds', 'snmp_port', 'last_ping_at', 'last_snmp_at']);
        });
    }
};
