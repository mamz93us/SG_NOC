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
            $table->unsignedBigInteger('branch_id')->nullable();      // null = all branches
            $table->unsignedBigInteger('department_id')->nullable();  // null = all departments
            $table->string('azure_group_id');
            $table->string('azure_group_name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');

            $table->index(['branch_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_department_group_mappings');
    }
};
