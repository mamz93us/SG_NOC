<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hourly up/down snapshots for entities that only store current state
     * (access points, VPN tunnels, monitored hosts). Powers uptime-over-time
     * charts on the NOC overview. Kept lean + pruned by retention.
     */
    public function up(): void
    {
        Schema::create('availability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);          // access_point | vpn_tunnel | monitored_host
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->boolean('up')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['entity_type', 'captured_at']);
            $table->index(['branch_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_snapshots');
    }
};
