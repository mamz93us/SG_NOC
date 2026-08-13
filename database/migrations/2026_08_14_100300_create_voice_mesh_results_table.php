<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history: one row per leg per run, written with a single raw
 * ::insert() the way tunnel_health_checks is.
 *
 * Volume: 7 nodes -> 42 legs per run, 48 runs/day = ~2,000 rows/day, so 30 days
 * is ~60k rows. The tunnel watchdog stores history at tunnel level rather than
 * probe level because at a 1-minute cadence per-probe would cost ~52k rows/day;
 * here the cadence is 30 minutes, so per-leg history is affordable — and per-leg
 * is the granularity the drill-down needs, since "has CAI->JED ever been flaky"
 * is unanswerable from a roll-up.
 *
 * Node ids are denormalised alongside the codes rather than joined through
 * voice_mesh_runs so the drill-down is one indexed range scan, and the codes
 * mean the history stays readable after a node is deleted.
 *
 * No foreign keys: this is a high-churn insert/prune table, and deleting a node
 * should not cascade away a month of forensic history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_mesh_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voice_mesh_run_id')->nullable();

            $table->unsignedBigInteger('caller_node_id')->nullable();
            $table->unsignedBigInteger('dest_node_id')->nullable();
            $table->string('caller_code', 16);
            $table->string('dest_code', 16);
            $table->string('dest_ext', 16)->nullable();

            $table->boolean('ok');
            $table->unsignedInteger('rx_pkt')->nullable();
            $table->decimal('duration_sec', 6, 2)->nullable();
            $table->decimal('reference_sec', 6, 2)->nullable();
            $table->string('reason', 255)->nullable();

            $table->timestamp('checked_at');

            $table->index(['caller_node_id', 'dest_node_id', 'checked_at'], 'vmr_pair_time_idx');
            $table->index('checked_at');
            $table->index('voice_mesh_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_mesh_results');
    }
};
