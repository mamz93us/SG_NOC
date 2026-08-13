<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per report POSTed by the prober. Low volume — 48/day at the default
 * 30-minute interval — so the raw payload is kept for forensic replay ("what
 * exactly did the prober say at 14:02").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_mesh_runs', function (Blueprint $table) {
            $table->id();
            $table->string('runner_name', 64)->nullable();
            $table->string('probe_version', 32)->nullable();

            // The prober's own clock, clamped on ingest — a probe host with a
            // wrong clock must not be able to reorder the history.
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('received_at');

            $table->boolean('ok')->default(false);
            $table->unsignedSmallInteger('pairs_total')->default(0);
            $table->unsignedSmallInteger('pairs_ok')->default(0);
            $table->unsignedSmallInteger('pairs_failed')->default(0);
            $table->unsignedSmallInteger('nodes_total')->default(0);

            $table->string('source_ip', 45)->nullable();
            // Codes present in the payload with no matching node — surfaced in
            // the UI so a typo shows up instead of silently dropping legs.
            $table->json('unknown_nodes')->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index('received_at');
            $table->index('ok');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_mesh_runs');
    }
};
