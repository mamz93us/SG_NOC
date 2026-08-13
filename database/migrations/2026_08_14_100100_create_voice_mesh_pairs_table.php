<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current status of each ordered (caller -> dest) leg. This is what the matrix
 * reads and what carries the consecutive-failure count the alerting thresholds
 * are based on.
 *
 * Bounded by design: N nodes means exactly N*(N-1) rows, upserted forever —
 * 42 for the seven branches. The append-only history lives in
 * voice_mesh_results.
 *
 * Rows materialise on first report rather than being pre-created, so adding a
 * node doesn't require hand-building its legs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_mesh_pairs', function (Blueprint $table) {
            $table->id();

            // These point at voice_mesh_nodes.id (a bigint), so foreignId() is
            // correct here — unlike the branches.id case next door.
            $table->foreignId('caller_node_id')->constrained('voice_mesh_nodes')->cascadeOnDelete();
            $table->foreignId('dest_node_id')->constrained('voice_mesh_nodes')->cascadeOnDelete();

            $table->string('status', 16)->default('unknown');   // ok | fail | unknown
            $table->string('last_reason', 255)->nullable();
            $table->string('last_dest_ext', 16)->nullable();    // what was actually dialled
            $table->unsignedInteger('last_rx_pkt')->nullable();
            $table->decimal('last_duration_sec', 6, 2)->nullable();
            $table->decimal('last_reference_sec', 6, 2)->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->timestamps();

            $table->unique(['caller_node_id', 'dest_node_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_mesh_pairs');
    }
};
