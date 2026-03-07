<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 50)->nullable()->index();  // Meraki client ID
            $table->string('switch_serial', 20)->nullable()->index();
            $table->string('mac', 20)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('hostname')->nullable();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('vlan')->nullable()->index();
            $table->string('port_id', 20)->nullable();
            $table->string('status', 20)->nullable();              // Online / Offline
            $table->unsignedBigInteger('usage_kb')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('os')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_clients');
    }
};
