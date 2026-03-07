<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop if a partial table was left behind by a previously failed migration attempt
        Schema::dropIfExists('employees');

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('azure_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable();
            // branches.id is unsignedInteger (not bigint), so we match the type explicitly
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable()->index();
            $table->string('job_title')->nullable();
            $table->enum('status', ['active', 'terminated', 'on_leave'])->default('active')->index();
            $table->date('hired_date')->nullable();
            $table->date('terminated_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
