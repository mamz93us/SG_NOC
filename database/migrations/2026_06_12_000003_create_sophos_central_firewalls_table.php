<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_central_firewalls', function (Blueprint $table) {
            $table->id();
            $table->string('central_id')->unique();       // Sophos Central firewall id
            $table->string('name')->nullable();
            $table->string('hostname')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('model')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('status')->nullable()->index(); // connected / disconnected / …
            $table->string('group_name')->nullable();
            $table->string('cluster_mode')->nullable();
            $table->json('available_firmware')->nullable(); // pending firmware upgrades reported by Central
            $table->json('raw')->nullable();
            // Optional link to the locally-managed firewall (matched by serial)
            $table->foreignId('sophos_firewall_id')->nullable()->constrained('sophos_firewalls')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_central_firewalls');
    }
};
