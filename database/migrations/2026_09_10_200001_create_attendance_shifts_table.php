<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When people are due in and out. Times are wall clock; an end that is not
 * after the start (22:00–06:00) is an overnight shift, kept in
 * crosses_midnight so "is any shift overnight?" is one cheap query.
 *
 * Late counts from the start once past grace_in; overtime is time after the
 * end once it reaches min_overtime_minutes; more than max_hours between
 * check-in and check-out is a forgotten check-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->unsignedSmallInteger('max_hours')->nullable()->default(16);
            $table->unsignedSmallInteger('min_overtime_minutes')->default(30);
            // ISO weekdays: 1 = Monday … 7 = Sunday.
            $table->json('off_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shifts');
    }
};
