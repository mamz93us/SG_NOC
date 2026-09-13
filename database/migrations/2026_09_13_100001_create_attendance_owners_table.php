<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attendance owner list: people who may ask the home-portal assistant
 * about the attendance of everyone in the company, or in chosen branches — a
 * general manager, a branch GM. Direct reports need no row here; the
 * employee's manager_id / supervisor_id already say who sees them.
 *
 * One row per person. The branches are a JSON list on the row rather than a
 * pivot table, so changing someone's branches is one audited update of this
 * row instead of pivot rows that nothing audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->string('scope', 20);
            $table->json('branch_ids')->nullable();
            $table->string('title', 150)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_owners');
    }
};
