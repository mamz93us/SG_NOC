<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the voice mesh tables by hand.
 *
 * The suite cannot use RefreshDatabase: several migrations in this repo are
 * MySQL-only (`MODIFY COLUMN`) and blow up on the SQLite connection phpunit.xml
 * configures, so a full migrate is unavailable. Shared by the unit and feature
 * tests so the two can't drift apart.
 */
class VoiceMeshSchema
{
    public static function create(): void
    {
        foreach (['voice_mesh_results', 'voice_mesh_pairs', 'voice_mesh_runs', 'voice_mesh_nodes', 'noc_events', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('voice_mesh_nodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('code', 16)->unique();
            $t->string('name', 100);
            $t->string('ivr_ext', 16);
            $t->string('sip_server', 45);
            $t->unsignedSmallInteger('sip_port')->default(5060);
            $t->string('sip_user', 64);
            $t->text('sip_pass');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->text('notes')->nullable();
            $t->string('state', 20)->default('unknown');
            $t->timestamp('state_changed_at')->nullable();
            $t->unsignedSmallInteger('consecutive_failures')->default(0);
            $t->timestamp('last_result_at')->nullable();
            $t->timestamps();
        });

        Schema::create('voice_mesh_pairs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('caller_node_id');
            $t->unsignedBigInteger('dest_node_id');
            $t->string('status', 16)->default('unknown');
            $t->string('last_reason', 255)->nullable();
            $t->string('last_dest_ext', 16)->nullable();
            $t->unsignedInteger('last_rx_pkt')->nullable();
            $t->decimal('last_duration_sec', 6, 2)->nullable();
            $t->decimal('last_reference_sec', 6, 2)->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->unsignedSmallInteger('consecutive_failures')->default(0);
            $t->timestamps();
            $t->unique(['caller_node_id', 'dest_node_id']);
        });

        Schema::create('voice_mesh_runs', function (Blueprint $t) {
            $t->id();
            $t->string('runner_name', 64)->nullable();
            $t->string('probe_version', 32)->nullable();
            $t->timestamp('reported_at')->nullable();
            $t->timestamp('received_at');
            $t->boolean('ok')->default(false);
            $t->unsignedSmallInteger('pairs_total')->default(0);
            $t->unsignedSmallInteger('pairs_ok')->default(0);
            $t->unsignedSmallInteger('pairs_failed')->default(0);
            $t->unsignedSmallInteger('nodes_total')->default(0);
            $t->string('source_ip', 45)->nullable();
            $t->json('unknown_nodes')->nullable();
            $t->json('payload')->nullable();
            $t->timestamps();
        });

        Schema::create('voice_mesh_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voice_mesh_run_id')->nullable();
            $t->unsignedBigInteger('caller_node_id')->nullable();
            $t->unsignedBigInteger('dest_node_id')->nullable();
            $t->string('caller_code', 16);
            $t->string('dest_code', 16);
            $t->string('dest_ext', 16)->nullable();
            $t->boolean('ok');
            $t->unsignedInteger('rx_pkt')->nullable();
            $t->decimal('duration_sec', 6, 2)->nullable();
            $t->decimal('reference_sec', 6, 2)->nullable();
            $t->string('reason', 255)->nullable();
            $t->timestamp('checked_at');
        });

        Schema::create('noc_events', function (Blueprint $t) {
            $t->id();
            $t->string('module')->nullable();
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->unsignedInteger('cooldown_minutes')->nullable();
            $t->timestamp('email_sent_at')->nullable();
            $t->string('severity')->nullable();
            $t->string('title')->nullable();
            $t->text('message')->nullable();
            $t->timestamp('first_seen')->nullable();
            $t->timestamp('last_seen')->nullable();
            $t->string('status')->default('open');
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        // Only the columns the voice mesh reads — Setting::get() creates the
        // singleton row with company_name/sso defaults.
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('company_name')->nullable();
            $t->string('company_logo')->nullable();
            $t->boolean('sso_enabled')->default(false);
            $t->string('sso_default_role')->nullable();
            $t->text('voice_mesh_secret')->nullable();
            $t->unsignedSmallInteger('voice_mesh_retention_days')->default(30);
            $t->timestamps();
        });
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string}>  $nodes  [code, ivr_ext, sip_server]
     */
    public static function seedNodes(array $nodes): void
    {
        foreach ($nodes as $i => [$code, $ivrExt, $sipServer]) {
            \App\Models\VoiceMeshNode::create([
                'code' => $code,
                'name' => $code.' Branch',
                'ivr_ext' => $ivrExt,
                'sip_server' => $sipServer,
                'sip_port' => 5060,
                'sip_user' => '9'.$ivrExt,
                'sip_pass' => 'secret-'.$code,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
