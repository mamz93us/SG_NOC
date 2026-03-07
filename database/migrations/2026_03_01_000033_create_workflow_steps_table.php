<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflow_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_number');
            $table->enum('approver_role', ['manager', 'it_manager', 'hr', 'security', 'super_admin']);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'skipped'])->default('pending')->index();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
