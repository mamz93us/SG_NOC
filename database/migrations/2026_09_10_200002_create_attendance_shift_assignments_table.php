<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who works which shift: everyone, a branch, a department or one employee
 * (scope_type + scope_id), from a date and optionally to a date. The most
 * specific assignment covering a day wins, then the most recent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_shift_id')->constrained('attendance_shifts');
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_assignments');
    }
};
