<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (source, emp_code) seen in punches, and the NOC employee it
 * belongs to. Per source because the same code in two BioTime databases can be
 * two different people.
 *
 * match_method: auto_empno | auto_empno_branch | manual | ambiguous | none.
 * A `manual` row is never touched by the auto-matcher; `manual` with no
 * employee_id means HR confirmed the code is not a NOC employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biotime_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biotime_source_id')->constrained('biotime_sources');
            $table->string('emp_code', 50);
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('match_method', 30)->nullable()->index();
            $table->json('candidate_ids')->nullable();
            $table->json('areas')->nullable();
            $table->dateTime('first_punch_at')->nullable();
            $table->dateTime('last_punch_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['biotime_source_id', 'emp_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biotime_employees');
    }
};
