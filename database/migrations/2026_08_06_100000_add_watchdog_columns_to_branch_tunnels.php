<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades the Branch Tunnel Health board into a watchdog.
 *
 * The old board stored a single ping result per branch (the firewall IP). That
 * is not enough: on 2026-07-05 the JED firewall at 10.1.0.1 kept answering ICMP
 * while the voice VLAN 10.1.8.0/24 behind the same tunnel went dark, so the
 * board showed JED green for a month while the UCM was unreachable.
 *
 * The firewall ping stays here as the *gateway* check (ping_status /
 * ping_latency_ms / last_ping_at keep their meaning). Per-subnet checks live in
 * tunnel_probes, and `state` is the roll-up across both:
 *   up       — gateway and every probe answering
 *   degraded — gateway answering, at least one probe down  ← the JED case
 *   down     — gateway not answering
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('name')
                ->constrained('branches')->nullOnDelete();

            // Roll-up across the gateway ping and every active probe.
            $table->string('state', 20)->default('unknown')->after('is_active');
            $table->timestamp('state_changed_at')->nullable()->after('state');

            // Consecutive gateway failures — lets the watchdog wait for N misses
            // before shouting, so one dropped ICMP packet is not an outage.
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_ping_at');

            $table->index(['is_active', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'state']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['state', 'state_changed_at', 'consecutive_failures']);
        });
    }
};
