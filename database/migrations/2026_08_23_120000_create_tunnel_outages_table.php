<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tunnel incident — a continuous stretch spent in `down` or
 * `degraded`, with the from/to timestamps you need to open a ticket with the ISP.
 *
 * Why this exists when tunnel_health_checks already records every cycle: that
 * table is pruned at 7 days (13k rows/day at a 1-minute cadence for 9 tunnels),
 * so it can answer "was the link flaky yesterday" but not "how many hours did
 * JED lose in June" — which is exactly the question an SLA credit claim asks.
 * Incidents are tiny by comparison, so they are kept indefinitely.
 *
 * NocEvents were the other candidate and are deliberately not used: the event
 * for a down tunnel is only raised after ALERT_AFTER_FAILURES cycles (start time
 * off by ~2 minutes) and the flap window re-opens a resolved event, merging two
 * separate outages into one long one. Both are right for paging, wrong for
 * billing.
 *
 * `checks` is the honesty column. Duration comes from the clock, but a watchdog
 * that was not running (NOC reboot, scheduler wedged) leaves an incident open
 * across the gap and would otherwise report the gap as downtime. Comparing
 * checks against elapsed minutes shows how much of the window was actually
 * observed, and the report flags anything under-covered rather than quietly
 * billing the ISP for our own outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnel_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_tunnel_id')->constrained('branch_tunnels')->cascadeOnDelete();

            $table->string('state', 20);                        // down | degraded
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();           // null = still running
            $table->unsignedInteger('duration_seconds')->nullable();

            // Cycles observed inside the incident, and the worst probe count seen.
            $table->unsignedInteger('checks')->default(1);
            $table->unsignedSmallInteger('probes_down')->default(0);

            // What was unreachable — the firewall IP for down, the failing probe
            // labels for degraded. This is the text that goes into the ticket.
            $table->text('reason')->nullable();

            // watchdog = written live on a state transition.
            // backfill  = reconstructed from tunnel_health_checks after the fact.
            $table->string('source', 20)->default('watchdog');

            // Set by hand from the report once a ticket is raised with the ISP.
            $table->string('ticket_ref')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['branch_tunnel_id', 'started_at']);
            $table->index(['state', 'started_at']);
            // Finding the open incident for a tunnel is done every single cycle.
            $table->index(['branch_tunnel_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnel_outages');
    }
};
