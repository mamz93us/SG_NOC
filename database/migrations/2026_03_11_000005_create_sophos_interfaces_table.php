<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->constrained('sophos_firewalls')->cascadeOnDelete();
            $table->string('name');
            $table->string('hardware')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('netmask')->nullable();
            $table->string('zone')->nullable();
            $table->string('status')->default('unknown');    // up, down, unknown
            $table->unsignedInteger('mtu')->nullable();
            $table->string('speed')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_interfaces');
    }
};
