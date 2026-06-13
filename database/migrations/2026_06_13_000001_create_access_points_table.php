<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_points', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vendor')->default('sophos');     // sophos | tp_link | other
            $table->string('controller')->nullable();         // sophos_central | omada | manual
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->string('mac_address')->nullable()->index();
            $table->string('ip_address')->nullable()->index();
            $table->string('site')->nullable();               // raw site/group from the source
            $table->unsignedInteger('branch_id')->nullable();
            $table->string('firmware')->nullable();
            $table->string('license_state')->nullable();
            $table->string('profile')->nullable();
            $table->string('config_status')->nullable();
            $table->string('channel_2g')->nullable();
            $table->string('channel_5g')->nullable();
            $table->string('channel_6g')->nullable();
            $table->unsignedTinyInteger('cpu_usage')->nullable();
            $table->unsignedTinyInteger('memory_usage')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            // Monitoring
            $table->boolean('monitor_enabled')->default(true);
            $table->string('status')->default('unknown');     // up | down | unknown
            $table->unsignedInteger('ping_latency_ms')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();    // last successful ping
            // Linkage
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index(['vendor', 'status']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_points');
    }
};
