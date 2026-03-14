<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_port_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ucm_server_id')->constrained('ucm_servers')->cascadeOnDelete();
            $table->string('extension', 20);
            $table->string('phone_ip', 45)->nullable();
            $table->string('phone_mac', 20)->nullable();
            $table->string('switch_name')->nullable();
            $table->string('switch_serial')->nullable();
            $table->string('switch_port', 20)->nullable();
            $table->unsignedSmallInteger('vlan')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['ucm_server_id', 'extension']);
            $table->index('phone_ip');
            $table->index('phone_mac');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_port_map');
    }
};
