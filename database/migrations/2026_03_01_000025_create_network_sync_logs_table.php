<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['started', 'completed', 'failed'])->default('started')->index();
            $table->unsignedInteger('switches_synced')->default(0);
            $table->unsignedInteger('ports_synced')->default(0);
            $table->unsignedInteger('clients_synced')->default(0);
            $table->unsignedInteger('events_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_sync_logs');
    }
};
