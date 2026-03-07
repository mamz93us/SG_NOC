<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_ports', function (Blueprint $table) {
            $table->id();
            $table->string('switch_serial', 20)->index();
            $table->string('port_id', 20);       // "1", "2", "uplink", etc.
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('type', 20)->nullable();     // access / trunk
            $table->unsignedSmallInteger('vlan')->nullable();
            $table->string('allowed_vlans', 255)->nullable();
            $table->boolean('poe_enabled')->default(false);
            $table->boolean('is_uplink')->default(false);
            $table->string('status', 30)->nullable();   // Connected / Disconnected / Disabled
            $table->string('speed', 30)->nullable();
            $table->string('duplex', 20)->nullable();
            $table->string('client_mac', 20)->nullable();
            $table->string('client_hostname')->nullable();
            $table->timestamps();

            $table->unique(['switch_serial', 'port_id']);
            $table->foreign('switch_serial')->references('serial')->on('network_switches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_ports');
    }
};
