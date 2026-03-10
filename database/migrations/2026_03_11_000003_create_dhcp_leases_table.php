<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_leases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('subnet_id')->nullable()->constrained('ipam_subnets')->nullOnDelete();
            $table->string('ip_address');
            $table->string('mac_address');
            $table->string('hostname')->nullable();
            $table->string('vendor')->nullable();
            $table->integer('vlan')->nullable();
            $table->string('source');                        // meraki, sophos, snmp
            $table->string('source_device')->nullable();     // switch serial or firewall IP
            $table->timestamp('lease_start')->nullable();
            $table->timestamp('lease_end')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('switch_serial')->nullable();
            $table->string('port_id')->nullable();
            $table->boolean('is_conflict')->default(false);
            $table->timestamps();

            $table->index('mac_address');
            $table->index('ip_address');
            $table->index('branch_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_leases');
    }
};
