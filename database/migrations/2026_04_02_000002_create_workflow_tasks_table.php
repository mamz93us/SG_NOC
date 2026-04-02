<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflow_requests')->cascadeOnDelete();
            $table->string('type', 60)->default('general')
                  ->comment('laptop_assign | ip_phone_assign | general');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending')
                  ->comment('pending | in_progress | completed | cancelled');
            $table->json('payload')->nullable()
                  ->comment('Extra details: UCM IP, user, password, laptop type, etc.');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_tasks');
    }
};
