<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tunnel per watchdog cycle — the raw material for the strip chart
 * and the rolling uptime figure on the watchdog page.
 *
 * Deliberately tunnel-level, not probe-level: at a 1-minute cadence 9 tunnels
 * cost ~13k rows/day, where per-probe would cost ~52k. The current per-probe
 * state lives on tunnel_probes; this table only needs to answer "what was the
 * roll-up, and how many probes were answering". Pruned by the watchdog itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnel_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_tunnel_id')->constrained('branch_tunnels')->cascadeOnDelete();

            $table->string('state', 20);                          // up | degraded | down
            $table->unsignedSmallInteger('probes_up')->default(0);
            $table->unsignedSmallInteger('probes_total')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();     // gateway latency
            $table->timestamp('checked_at');

            // Composite index drives both the strip query (per tunnel, newest
            // first) and the retention sweep.
            $table->index(['branch_tunnel_id', 'checked_at']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnel_health_checks');
    }
};
