<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the tables the branch health score reads, by hand.
 *
 * The suite cannot use RefreshDatabase: 37 statements across this repo's
 * migrations are MySQL-only `MODIFY COLUMN` DDL and abort on the SQLite
 * connection phpunit.xml configures, so a full migrate is unavailable. Same
 * reasoning and same approach as VoiceMeshSchema — see its docblock.
 *
 * Only the columns the scorer touches are declared. This is deliberately a
 * narrow mirror of the real schema rather than a copy of it; where a column's
 * type matters to the logic under test (branches.id being an unsignedInteger,
 * printer_supplies.supply_percent being unsigned so it cannot hold the -1/-2/-3
 * sentinels) that is reproduced faithfully.
 */
class BranchHealthSchema
{
    /** Tables dropped in FK-safe order, children first. */
    private const TABLES = [
        'printer_supplies', 'printers', 'link_checks', 'isp_connections',
        'tunnel_probes', 'branch_tunnels', 'sophos_firewalls',
        'access_points', 'network_switches', 'monitored_hosts', 'devices',
        'voice_quality_reports', 'ucm_trunks_cache', 'ucm_servers',
        'voice_mesh_pairs', 'voice_mesh_nodes', 'ipam_subnets',
        'noc_events', 'settings', 'branches',
    ];

    public static function create(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        // branches.id is a manual unsignedInteger, not an auto-incrementing
        // bigint. Every FK pointing at it in the real schema must match that
        // width or MySQL rejects the constraint with errno 3780.
        Schema::create('branches', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name');
            $t->unsignedBigInteger('ucm_server_id')->nullable();
            $t->unsignedInteger('ext_range_start')->nullable();
            $t->unsignedInteger('ext_range_end')->nullable();
            $t->timestamps();
        });

        // Wider than the scorer strictly needs: the admin layout calls
        // Setting::get(), which CREATES the singleton row, so rendering a page
        // in a test writes here. (That side effect is exactly why the telemetry
        // loader uses Setting::first() instead.)
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('company_name')->nullable();
            $t->string('company_logo')->nullable();
            $t->boolean('sso_enabled')->default(false);
            $t->string('sso_default_role')->nullable();
            $t->unsignedInteger('meraki_polling_interval')->default(15);
            $t->timestamps();
        });

        // ── VoIP ─────────────────────────────────────────────────────
        Schema::create('ucm_servers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('url')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('last_health_ok')->nullable();
            $t->timestamp('last_health_at')->nullable();
            $t->string('last_health_error', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('ucm_trunks_cache', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ucm_id');
            $t->string('trunk_name')->nullable();
            $t->string('trunk_index', 20)->nullable();
            $t->string('host')->nullable();
            $t->string('status', 30)->default('unreachable');
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('voice_mesh_nodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('code', 16)->unique();
            $t->string('name', 100);
            $t->string('ivr_ext', 16)->nullable();
            $t->string('sip_server', 45)->nullable();
            $t->unsignedSmallInteger('sip_port')->default(5060);
            $t->string('sip_user', 64)->nullable();
            $t->text('sip_pass')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->string('state', 20)->default('unknown');
            $t->timestamps();
        });

        Schema::create('voice_mesh_pairs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('caller_node_id');
            $t->unsignedBigInteger('dest_node_id');
            $t->string('status', 16)->default('unknown');
            $t->string('last_reason', 255)->nullable();
            $t->unsignedInteger('last_rx_pkt')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->unsignedSmallInteger('consecutive_failures')->default(0);
            $t->timestamps();
            $t->unique(['caller_node_id', 'dest_node_id']);
        });

        Schema::create('voice_quality_reports', function (Blueprint $t) {
            $t->id();
            $t->string('extension')->nullable();
            $t->string('branch')->nullable();
            $t->unsignedInteger('branch_id')->nullable();
            $t->float('mos_lq')->nullable();
            $t->timestamps();
        });

        // ── Network ──────────────────────────────────────────────────
        Schema::create('sophos_firewalls', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->string('ip')->nullable();
            $t->unsignedBigInteger('monitored_host_id')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('branch_tunnels', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('firewall_ip', 45)->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('state', 20)->default('unknown');
            $t->string('ping_status', 20)->default('unknown');
            $t->timestamp('last_ping_at')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('tunnel_probes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_tunnel_id');
            $t->string('label')->nullable();
            $t->string('target', 45)->nullable();
            $t->string('check_type', 10)->default('icmp');
            $t->boolean('is_active')->default(true);
            $t->string('status', 20)->default('unknown');
            $t->timestamp('last_checked_at')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('isp_connections', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id');
            $t->string('provider')->nullable();
            $t->string('gateway')->nullable();
            $t->string('static_ip')->nullable();
            $t->timestamps();
        });

        Schema::create('link_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('isp_id');
            $t->float('latency')->nullable();
            $t->float('packet_loss')->default(0);
            $t->boolean('success')->default(true);
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('access_points', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('vendor')->default('sophos');
            $t->boolean('monitor_enabled')->default(true);
            $t->string('status')->default('unknown');
            $t->timestamp('last_ping_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->timestamps();
        });

        // ── Devices ──────────────────────────────────────────────────
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->string('type', 30)->default('other');
            $t->string('ip_address')->nullable();
            $t->string('asset_code')->nullable();
            $t->timestamps();
        });

        Schema::create('network_switches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->string('serial', 40)->nullable();
            $t->string('status', 20)->default('unknown');
            $t->timestamp('last_reported_at')->nullable();
            $t->timestamps();
        });

        Schema::create('monitored_hosts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->string('ip')->nullable();
            $t->string('type')->nullable();
            $t->boolean('ping_enabled')->default(true);
            $t->string('status')->default('unknown');
            $t->timestamp('last_ping_at')->nullable();
            $t->timestamps();
        });

        Schema::create('printers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->unsignedInteger('branch_id')->nullable();
            $t->string('printer_name')->nullable();
            $t->string('ip_address')->nullable();
            // Sentinels live here: -1 unknown, -2 n/a, -3 some remaining.
            $t->smallInteger('toner_black')->nullable();
            $t->smallInteger('toner_cyan')->nullable();
            $t->smallInteger('toner_magenta')->nullable();
            $t->smallInteger('toner_yellow')->nullable();
            $t->timestamp('snmp_last_polled_at')->nullable();
            $t->timestamps();
        });

        Schema::create('printer_supplies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('printer_id');
            $t->unsignedSmallInteger('supply_index')->nullable();
            $t->string('supply_type')->default('toner');
            $t->string('supply_color')->nullable();
            // Unsigned in the real schema too, which is why the -1/-2/-3
            // sentinels can only ever appear on the legacy printers.toner_* path.
            $t->unsignedTinyInteger('supply_percent')->nullable();
            $t->timestamp('last_updated_at')->nullable();
            $t->timestamps();
        });

        // ── Shared ───────────────────────────────────────────────────
        Schema::create('ipam_subnets', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id');
            $t->string('cidr');
            $t->string('gateway')->nullable();
            $t->unsignedInteger('total_ips')->default(0);
            $t->timestamps();
        });

        Schema::create('noc_events', function (Blueprint $t) {
            $t->id();
            $t->string('module', 50)->nullable();
            $t->unsignedInteger('branch_id')->nullable();
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
    }

    /** @param  array<int, array{0:int,1:string}>  $branches  [id, name] */
    public static function seedBranches(array $branches): void
    {
        foreach ($branches as [$id, $name]) {
            DB::table('branches')->insert([
                'id' => $id,
                'name' => $name,
                'ext_range_start' => $id * 1000,
                'ext_range_end' => $id * 1000 + 999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
