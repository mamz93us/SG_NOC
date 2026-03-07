<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ── 1. VPN Tunnels ──
        Schema::create('vpn_tunnels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->string('name')->unique();
            $table->string('remote_public_ip');
            $table->string('remote_subnet');
            $table->string('local_subnet');
            $table->text('pre_shared_key'); // encrypted cast in model
            $table->string('ike_version')->default('IKEv2');
            $table->string('encryption')->default('AES256');
            $table->string('hash')->default('SHA256');
            $table->integer('dh_group')->default(14);
            $table->integer('dpd_delay')->default(30);
            $table->string('lifetime')->default('8h');
            $table->enum('status', ['up', 'down', 'connecting'])->default('down');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        // ── 2. VPN Logs ──
        Schema::create('vpn_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_id')->constrained('vpn_tunnels')->onDelete('cascade');
            $table->string('event_type'); // up, down, reload, error
            $table->text('message')->nullable();
            $table->timestamps();
        });

        // ── 3. Monitored Hosts ──
        Schema::create('monitored_hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->foreignId('vpn_id')->nullable()->constrained('vpn_tunnels')->onDelete('set null');
            $table->string('name');
            $table->string('ip');
            $table->string('type'); // gateway, switch, ucm, printer, server
            $table->boolean('snmp_enabled')->default(false);
            $table->string('snmp_version')->default('v2c');
            $table->text('snmp_community')->nullable(); // encrypted cast in model
            $table->enum('status', ['up', 'down'])->default('up');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        // ── 4. Network Checks (Ping/TCP results) ──
        Schema::create('network_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('monitored_hosts')->onDelete('cascade');
            $table->float('latency')->nullable(); // in ms
            $table->float('packet_loss')->default(0); // percentage
            $table->boolean('success')->default(true);
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
        });

        // ── 5. MIB Support ──
        Schema::create('mibs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->timestamps();
        });

        // ── 6. SNMP Sensors (Custom OIDs) ──
        Schema::create('snmp_sensors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('monitored_hosts')->onDelete('cascade');
            $table->string('oid');
            $table->string('description')->nullable();
            $table->boolean('graph_enabled')->default(true);
            $table->timestamps();
        });

        // ── 7. Metrics (5-minute data points) ──
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('monitored_hosts')->onDelete('cascade');
            $table->string('metric_name'); // cpu, bandwidth_in, bandwidth_out, latency, uptime
            $table->float('value');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['host_id', 'metric_name', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
        Schema::dropIfExists('snmp_sensors');
        Schema::dropIfExists('mibs');
        Schema::dropIfExists('network_checks');
        Schema::dropIfExists('monitored_hosts');
        Schema::dropIfExists('vpn_logs');
        Schema::dropIfExists('vpn_tunnels');
    }
};
