<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holidays, for every branch (branch_id null) or one branch — Egypt and KSA
 * keep different calendars. No absence on a holiday; punches on one are overtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->index();
            $table->string('name', 150);
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_holidays');
    }
};
