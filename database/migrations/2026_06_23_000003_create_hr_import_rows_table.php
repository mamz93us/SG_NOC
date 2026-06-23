<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_import_batch_id')->constrained('hr_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->default(0);

            // Raw Oracle columns
            $table->string('emp_no')->nullable();
            $table->string('emp_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_raw')->nullable();
            $table->string('mobile_normalized')->nullable();
            $table->string('location_name')->nullable();
            $table->string('dept_no')->nullable();
            $table->string('dept_name')->nullable();
            $table->string('job_name')->nullable();

            // Matching + resolution
            $table->unsignedBigInteger('matched_employee_id')->nullable();
            $table->string('match_method')->default('none'); // email|upn|mail|manual|none
            $table->unsignedInteger('resolved_branch_id')->nullable();

            // matched|unmatched|applied|skipped|created|linked|error
            $table->string('status')->default('unmatched')->index();
            $table->string('decision')->nullable();           // create|skip|link
            $table->unsignedBigInteger('linked_employee_id')->nullable();
            $table->string('error_note')->nullable();

            $table->timestamps();

            $table->foreign('matched_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('linked_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('resolved_branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_import_rows');
    }
};
