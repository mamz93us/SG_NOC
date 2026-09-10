<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shift made of each day. window_start/window_end record which
 * punches the day was built from — past midnight for an overnight shift — so
 * the day page shows exactly those punches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->string('status', 20)->default('present')->after('work_date');
            $table->unsignedBigInteger('attendance_shift_id')->nullable()->after('branch_id');
            $table->dateTime('scheduled_start')->nullable()->after('emp_codes');
            $table->dateTime('scheduled_end')->nullable()->after('scheduled_start');
            $table->dateTime('window_start')->nullable()->after('scheduled_end');
            $table->dateTime('window_end')->nullable()->after('window_start');
            $table->unsignedInteger('late_minutes')->default(0)->after('worked_minutes');
            $table->unsignedInteger('early_leave_minutes')->default(0)->after('late_minutes');
            $table->unsignedInteger('overtime_minutes')->default(0)->after('early_leave_minutes');
            $table->string('excuse', 30)->nullable()->after('overtime_minutes');
            $table->unsignedBigInteger('attendance_adjustment_id')->nullable()->after('excuse');

            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropIndex(['work_date', 'status']);
            $table->dropColumn([
                'status', 'attendance_shift_id', 'scheduled_start', 'scheduled_end', 'window_start', 'window_end',
                'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'excuse', 'attendance_adjustment_id',
            ]);
        });
    }
};
