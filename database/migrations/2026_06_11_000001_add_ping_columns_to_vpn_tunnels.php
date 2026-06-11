<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_tunnels', function (Blueprint $table) {
            // Connectivity double-check: vpn:ping-tunnels pings a host inside
            // the remote subnet through the tunnel every 10 minutes. Target
            // defaults to the first host of the first remote subnet (branch
            // Sophos at 10.x.0.1); ping_target_ip overrides it.
            $table->string('ping_target_ip')->nullable()->after('status');
            $table->string('ping_status')->nullable()->after('ping_target_ip'); // up|down
            $table->unsignedInteger('ping_latency_ms')->nullable()->after('ping_status');
            $table->timestamp('last_ping_at')->nullable()->after('ping_latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_tunnels', function (Blueprint $table) {
            $table->dropColumn(['ping_target_ip', 'ping_status', 'ping_latency_ms', 'last_ping_at']);
        });
    }
};
