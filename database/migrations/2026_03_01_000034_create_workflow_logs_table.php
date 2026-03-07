<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflow_requests')->cascadeOnDelete();
            $table->enum('level', ['info', 'warning', 'error', 'success'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
    }
};
