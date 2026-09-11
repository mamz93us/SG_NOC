<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day inside an approved period is locked: the processor never rewrites or
 * deletes it, so what was approved is what Oracle gets. Reopening the period
 * unlocks its days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('has_error');
            $table->unsignedBigInteger('attendance_period_id')->nullable()->after('locked')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropIndex(['attendance_period_id']);
            $table->dropColumn(['locked', 'attendance_period_id']);
        });
    }
};
