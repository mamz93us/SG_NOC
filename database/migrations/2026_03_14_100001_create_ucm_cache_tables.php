<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ucm_extensions_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ucm_id')->constrained('ucm_servers')->cascadeOnDelete();
            $table->string('extension', 20);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 30)->default('unavailable');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['ucm_id', 'extension']);
            $table->index('ip_address');
            $table->index('status');
        });

        Schema::create('ucm_trunks_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ucm_id')->constrained('ucm_servers')->cascadeOnDelete();
            $table->string('trunk_name');
            $table->string('trunk_index', 20)->nullable();
            $table->string('host')->nullable();
            $table->string('status', 30)->default('unreachable');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['ucm_id', 'trunk_index']);
        });

        Schema::create('ucm_active_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ucm_id')->constrained('ucm_servers')->cascadeOnDelete();
            $table->string('caller', 50);
            $table->string('callee', 50);
            $table->timestamp('start_time')->nullable();
            $table->timestamp('answered_time')->nullable();
            $table->integer('duration')->default(0);
            $table->string('call_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ucm_active_calls');
        Schema::dropIfExists('ucm_trunks_cache');
        Schema::dropIfExists('ucm_extensions_cache');
    }
};
