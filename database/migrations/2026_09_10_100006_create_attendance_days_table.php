<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per work day, DERIVED from attendance_punches by
 * AttendanceDayProcessor and safe to rebuild at any time.
 *
 * Check-in is the earliest punch of the day and check-out the latest.
 *
 * subject_key is `emp:{employee_id}` for a linked person, or
 * `bt:{biotime_employee_id}` for a code not yet linked — a unique index over
 * a nullable employee_id would let unmapped days duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->string('subject_key', 40);
            $table->date('work_date');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('biotime_employee_id')->nullable()->index();
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->string('emp_codes', 150)->nullable();
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->json('flags')->nullable();
            $table->boolean('has_error')->default(false);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_key', 'work_date']);
            $table->index(['work_date', 'has_error']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
    }
};
