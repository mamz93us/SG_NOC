<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BioTime `area_alias` values, collected from punches and mapped once to a NOC
 * branch. Every punch carries its area, so this is how a punch — and an
 * emp_code that matches two employees — gets a branch.
 *
 * `timezone` is the wall clock the area's devices keep (punch_time has none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biotime_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biotime_source_id')->constrained('biotime_sources');
            $table->string('area_alias', 100);
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->string('timezone', 64)->nullable();
            // Device wall-clock time, so DATETIME — a TIMESTAMP would be shifted by the session zone.
            $table->dateTime('last_punch_at')->nullable();
            $table->timestamps();

            $table->unique(['biotime_source_id', 'area_alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biotime_areas');
    }
};
