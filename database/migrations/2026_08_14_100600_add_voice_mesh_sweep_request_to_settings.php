<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Run a sweep now" from the admin UI.
 *
 * The prober is a systemd unit on the NOC host, so the web user cannot start it
 * — and a full sweep takes minutes, so it could never be synchronous anyway.
 * Instead the UI leaves a request here; the prober reads it from
 * /api/voice-mesh/config on its next wake (every minute) and runs regardless of
 * the configured interval.
 *
 * `scope` is null for the whole mesh, or "CALLER>DEST" to re-test one leg —
 * one call rather than N*(N-1), for confirming a fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'voice_mesh_sweep_requested_at')) {
                $table->timestamp('voice_mesh_sweep_requested_at')->nullable();
            }
            if (! Schema::hasColumn('settings', 'voice_mesh_sweep_scope')) {
                $table->string('voice_mesh_sweep_scope', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['voice_mesh_sweep_requested_at', 'voice_mesh_sweep_scope'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
