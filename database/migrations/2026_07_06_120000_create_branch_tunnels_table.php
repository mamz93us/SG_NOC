<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone targets for the Branch Tunnel Health page. Each row is just a
 * branch label + the firewall IP we ping to confirm the (Azure-gateway) tunnel
 * is carrying traffic. Deliberately NOT tied to vpn_tunnels — the strongSwan
 * hub is gone; tunnels are built on the Azure VPN gateway now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_tunnels', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // branch label, e.g. "CAI"
            $table->string('firewall_ip', 45);            // IPv4/IPv6 to ping
            $table->boolean('is_active')->default(true);
            $table->string('ping_status')->default('unknown'); // up | down | unknown
            $table->unsignedInteger('ping_latency_ms')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_tunnels');
    }
};
