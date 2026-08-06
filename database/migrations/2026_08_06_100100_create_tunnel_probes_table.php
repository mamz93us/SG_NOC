<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subnet reachability checks carried out through a branch tunnel.
 *
 * One row per thing we expect to reach on the far side: the voice VLAN core,
 * the UCM's HTTPS API port, a floor switch, a print server. A tunnel is only
 * genuinely healthy when every one of these answers — pinging the firewall
 * alone hides a traffic selector that is missing a subnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnel_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_tunnel_id')->constrained('branch_tunnels')->cascadeOnDelete();

            $table->string('label');                 // e.g. "Voice VLAN core", "UCM API"
            $table->string('target', 45);            // IPv4/IPv6 to probe
            $table->string('check_type', 10)->default('icmp');  // icmp | tcp
            $table->unsignedSmallInteger('port')->nullable();    // required when check_type = tcp

            $table->boolean('is_active')->default(true);

            $table->string('status', 20)->default('unknown');    // up | down | unknown
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_tunnel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnel_probes');
    }
};
