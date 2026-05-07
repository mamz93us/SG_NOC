<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator-managed list of SNMP devices each branch's Telegraf
     * polls. Branches pull this list from /api/branch-config/snmp-devices
     * every few minutes, render their Telegraf config, and reload.
     */
    public function up(): void
    {
        Schema::create('snmp_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_log_collector_id')
                  ->constrained('branch_log_collectors')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('host', 255)->comment('LAN IP or hostname reachable from the branch VM');
            $table->enum('snmp_version', ['1', '2c', '3'])->default('2c');
            $table->text('snmp_community')->nullable()
                  ->comment('SNMP v1/v2c community string. Encrypted at rest via the model cast.');
            $table->unsignedSmallInteger('snmp_port')->default(161);
            $table->string('device_type', 32)
                  ->comment('sophos_xgs | switch_generic | tplink_omada_ap | grandstream_ucm | phone_icmp | generic_snmp');
            $table->unsignedSmallInteger('polling_interval_s')->default(60);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_status', 16)->nullable()->comment('healthy | unreachable | snmp_timeout');
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_log_collector_id', 'host']);
            $table->index(['device_type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_devices');
    }
};
