<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to hand an approved period to Oracle, with the exact payload
 * built for it — so what was sent can always be shown, downloaded and sent
 * again.
 *
 * status: prepared (built and stored; the stub sender stops here while the
 * Oracle API does not exist) | sent | failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_period_id')->constrained('attendance_periods');
            $table->string('sender', 100);
            $table->string('status', 20);
            $table->unsignedInteger('record_count')->default(0);
            $table->longText('payload');
            $table->text('response')->nullable();
            $table->string('reference', 191)->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exports');
    }
};
