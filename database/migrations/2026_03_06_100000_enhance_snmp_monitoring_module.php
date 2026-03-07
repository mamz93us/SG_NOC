<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->double('last_raw_counter')->nullable()->after('critical_threshold');
            $table->timestamp('last_recorded_at')->nullable()->after('last_raw_counter');
            $table->string('status', 20)->default('active')->after('last_recorded_at'); // active, unreachable, error
            $table->string('sensor_group')->nullable()->after('status');
            $table->unsignedInteger('interface_index')->nullable()->after('sensor_group');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('interface_index');
        });

        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->string('discovered_type')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_sensors', function (Blueprint $table) {
            $table->dropColumn([
                'last_raw_counter',
                'last_recorded_at',
                'status',
                'sensor_group',
                'interface_index',
                'consecutive_failures',
            ]);
        });

        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropColumn('discovered_type');
        });
    }
};
