<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device that has run the wallpaper agent. The PowerShell script
 * POSTs a check-in to /api/wallpapers/checkin after it applies, so the NOC can
 * show which wallpaper set each machine actually matched + when it last ran.
 * Upserted by hostname, so the table holds the current state per device, not a
 * full history (created_at = first seen, updated_at = last check-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallpaper_checkins', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique();
            $table->string('domain_detected')->nullable();
            $table->foreignId('wallpaper_set_id')->nullable()->constrained('wallpaper_sets')->nullOnDelete();
            $table->string('set_label')->nullable();      // denormalised so it survives set deletion
            $table->string('desktop_hash', 64)->nullable();
            $table->string('lockscreen_hash', 64)->nullable();
            $table->string('os_version')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('checkin_count')->default(1);
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallpaper_checkins');
    }
};
