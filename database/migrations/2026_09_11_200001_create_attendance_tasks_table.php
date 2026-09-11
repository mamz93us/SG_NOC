<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance work the pages ask for but must not do inline: a sync, a
 * recalculation after a shift or holiday change, re-matching codes. Doing it
 * in the request held a PHP-FPM worker for minutes and ended in a 504.
 *
 * Production has no queue worker — the scheduler is the worker — so these are
 * rows that `attendance:work` (every minute) picks up in order.
 *
 * type: sync | rebuild | relink. status: pending | running | done | failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->json('payload')->nullable();
            $table->string('label', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('result')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_tasks');
    }
};
