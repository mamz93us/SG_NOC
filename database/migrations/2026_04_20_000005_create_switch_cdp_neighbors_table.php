<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_cdp_neighbors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('device_name');
            $table->string('device_ip', 45)->index();

            $table->string('local_interface', 40);
            $table->string('neighbor_device_id', 100);   // "5c0610617cda" or "Core-SW-2" or FQDN
            $table->string('neighbor_ip', 45)->nullable();
            $table->string('neighbor_port', 40)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('capabilities', 40)->nullable();  // "S I R"
            $table->string('version', 255)->nullable();
            $table->unsignedSmallInteger('holdtime')->nullable();

            $table->timestamp('polled_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'polled_at']);
            $table->index('neighbor_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_cdp_neighbors');
    }
};
