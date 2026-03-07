<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service');          // 'meraki' | 'gdms' | 'identity'
            $table->string('status');           // 'running' | 'completed' | 'failed'
            $table->integer('records_synced')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['service', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sync_logs');
    }
};
