<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw punches, copied verbatim from BioTime's iclock_transaction:
 *   id, emp_code, punch_time, punch_state, terminal_sn, terminal_alias, area_alias
 *
 * These rows are never edited by HR — corrections are separate records and
 * attendance_days is derived from both. punch_time is the device's wall clock
 * with no zone, so it is a DATETIME and is never converted.
 *
 * biotime_id is unique per source only: two databases reuse the same ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biotime_source_id')->constrained('biotime_sources');
            $table->unsignedBigInteger('biotime_id');
            $table->foreignId('biotime_employee_id')->constrained('biotime_employees');
            // Denormalised from biotime_employees so a day is one indexed read.
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('emp_code', 50);
            $table->dateTime('punch_time');
            $table->string('punch_state', 10)->nullable();
            $table->string('terminal_sn', 100)->nullable();
            $table->string('terminal_alias', 150)->nullable();
            $table->string('area_alias', 100)->nullable();
            $table->timestamp('synced_at');

            $table->unique(['biotime_source_id', 'biotime_id']);
            $table->index(['employee_id', 'punch_time']);
            $table->index(['biotime_employee_id', 'punch_time']);
            $table->index('punch_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
