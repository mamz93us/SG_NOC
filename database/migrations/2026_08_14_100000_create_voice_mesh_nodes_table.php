<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per branch taking part in the synthetic call mesh.
 *
 * This table is both the prober's configuration (the branch list it fetches at
 * run time, replacing the hand-edited config.conf it used to carry) and that
 * branch's rolled-up state from the last run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_mesh_nodes', function (Blueprint $table) {
            $table->id();

            // NOTE on branch_id: `branches.id` is a legacy `int unsigned`, not a
            // bigint. `foreignId()` would emit `bigint unsigned` and MySQL rejects
            // the foreign key with errno 3780 ("incompatible" columns), so the
            // column is declared explicitly and the FK added separately below.
            $table->unsignedInteger('branch_id')->nullable();

            $table->string('code', 16)->unique();       // CAI / JED — matches the prober's caller & dest strings
            $table->string('name', 100);

            // What OTHER branches dial to reach this one (its IVR extension).
            $table->string('ivr_ext', 16);

            // This branch's own UCM, as reached over the tunnel, and the probe
            // extension the prober registers as when impersonating this branch.
            $table->string('sip_server', 45);
            $table->unsignedSmallInteger('sip_port')->default(5060);
            $table->string('sip_user', 64);
            // Ciphertext, not a hash — the config endpoint has to hand the
            // plaintext back to pjsua, so it must be reversible. AES-256-CBC
            // output is ~230 bytes, well past a varchar(64).
            $table->text('sip_pass');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            // Rolled up from this node's legs on the most recent run.
            $table->string('state', 20)->default('unknown');    // up | degraded | down | unknown
            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_result_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'state']);
        });

        Schema::table('voice_mesh_nodes', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_mesh_nodes');
    }
};
