<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovery inbox. Branch VMs nmap-scan their local subnets, probe
     * SNMP responders, and POST findings to /api/branch-config/discovered.
     * Operator approves rows here → they move into snmp_devices for
     * active polling.
     */
    public function up(): void
    {
        Schema::create('snmp_discovered_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_log_collector_id')
                  ->constrained('branch_log_collectors')->cascadeOnDelete();
            $table->string('host', 255);
            $table->string('mac', 32)->nullable();
            $table->text('sys_descr')->nullable()->comment('SNMP sysDescr.0 — used to guess vendor/type');
            $table->string('sys_name', 128)->nullable();
            $table->string('suggested_type', 32)->nullable()
                  ->comment('Auto-guessed device_type (sophos_xgs, switch_generic, …)');
            $table->boolean('snmp_responding')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('seen_count')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_log_collector_id', 'host']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_discovered_devices');
    }
};
