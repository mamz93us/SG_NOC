<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_network_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->constrained('sophos_firewalls')->cascadeOnDelete();
            $table->string('name');
            $table->string('object_type')->nullable();       // IP, Network, Range
            $table->string('ip_address')->nullable();
            $table->string('subnet')->nullable();
            $table->string('host_type')->nullable();
            $table->boolean('ipam_synced')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_network_objects');
    }
};
