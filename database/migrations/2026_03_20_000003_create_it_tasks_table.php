<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('it_tasks');
        
        Schema::create('it_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('type', ['maintenance', 'project', 'support', 'change', 'other'])->default('other');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['todo', 'in_progress', 'blocked', 'on_hold', 'done'])->default('todo');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->decimal('estimated_hours', 5, 1)->unsigned()->nullable();
            $table->decimal('logged_hours', 5, 1)->unsigned()->default(0);
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('assigned_to');
            $table->index('branch_id');
            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_tasks');
    }
};
