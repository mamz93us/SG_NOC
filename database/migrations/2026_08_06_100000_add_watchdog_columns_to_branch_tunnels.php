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
 *
 * NOTE on branch_id: `branches.id` is a legacy `int unsigned`, not a bigint, and
 * the 36 other tables with a branch_id follow it. `foreignId()` would emit
 * `bigint unsigned` and MySQL rejects the foreign key with errno 3780
 * ("incompatible" columns), so the column is declared explicitly instead.
 * Every step is guarded so a partially-applied run converges cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An earlier run of this migration could have added branch_id as a
        // bigint before failing on the foreign key. It has never held data
        // (nothing writes it until the watchdog ships), so drop and redo it at
        // the correct width rather than leaving a column no FK can reference.
        if (Schema::hasColumn('branch_tunnels', 'branch_id')) {
            Schema::table('branch_tunnels', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }

        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->unsignedInteger('branch_id')->nullable()->after('name');
        });

        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::table('branch_tunnels', function (Blueprint $table) {
            // Roll-up across the gateway ping and every active probe.
            if (! Schema::hasColumn('branch_tunnels', 'state')) {
                $table->string('state', 20)->default('unknown')->after('is_active');
            }
            if (! Schema::hasColumn('branch_tunnels', 'state_changed_at')) {
                $table->timestamp('state_changed_at')->nullable()->after('state');
            }
            // Consecutive gateway failures — lets the watchdog wait for N misses
            // before shouting, so one dropped ICMP packet is not an outage.
            if (! Schema::hasColumn('branch_tunnels', 'consecutive_failures')) {
                $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_ping_at');
            }
        });

        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->index(['is_active', 'state'], 'branch_tunnels_is_active_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('branch_tunnels', function (Blueprint $table) {
            $table->dropIndex('branch_tunnels_is_active_state_index');
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'state', 'state_changed_at', 'consecutive_failures']);
        });
    }
};
