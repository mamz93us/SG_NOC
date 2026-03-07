<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'create_user', 'delete_user', 'license_change',
                'asset_assign', 'asset_return',
                'extension_create', 'extension_delete', 'other',
            ])->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            // branches.id is unsignedInteger (not bigint), so we match the type explicitly
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->enum('status', [
                'draft', 'pending', 'approved', 'rejected',
                'executing', 'completed', 'failed',
            ])->default('draft')->index();
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->unsignedTinyInteger('total_steps')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_requests');
    }
};
