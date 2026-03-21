<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_department_group_mappings', function (Blueprint $table) {
            $table->id();

            // branches.id is unsignedInteger (32-bit) — must match exactly
            $table->unsignedInteger('branch_id')->nullable();    // null = any branch
            // departments.id and identity_groups.id use $table->id() = unsignedBigInteger (64-bit)
            $table->unsignedBigInteger('department_id')->nullable(); // null = any department
            $table->unsignedBigInteger('identity_group_id');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')
                  ->references('id')->on('branches')
                  ->onDelete('cascade');

            $table->foreign('department_id')
                  ->references('id')->on('departments')
                  ->onDelete('cascade');

            $table->foreign('identity_group_id')
                  ->references('id')->on('identity_groups')
                  ->onDelete('cascade');

            // Prevent exact duplicate mappings
            $table->unique(['branch_id', 'department_id', 'identity_group_id'],
                           'bdgm_branch_dept_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_department_group_mappings');
    }
};
