<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hourly rollups — kept 90 days
        Schema::create('sensor_metrics_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_id')->constrained('snmp_sensors')->cascadeOnDelete();
            $table->timestamp('hour');          // Truncated to start of hour (e.g. 2026-03-20 14:00:00)
            $table->double('value_avg');        // Average value in this hour
            $table->double('value_min');        // Minimum value
            $table->double('value_max');        // Maximum value
            $table->unsignedSmallInteger('sample_count')->default(1);
            $table->timestamps();

            $table->unique(['sensor_id', 'hour']);
            $table->index('hour');
        });

        // Daily rollups — kept forever
        Schema::create('sensor_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_id')->constrained('snmp_sensors')->cascadeOnDelete();
            $table->date('date');               // Date (YYYY-MM-DD)
            $table->double('value_avg');
            $table->double('value_min');
            $table->double('value_max');
            $table->unsignedSmallInteger('sample_count')->default(1);
            $table->timestamps();

            $table->unique(['sensor_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_metrics_daily');
        Schema::dropIfExists('sensor_metrics_hourly');
    }
};
