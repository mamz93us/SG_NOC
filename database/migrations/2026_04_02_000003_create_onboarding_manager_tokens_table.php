<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_manager_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('workflow_id')->constrained('workflow_requests')->cascadeOnDelete();
            $table->string('manager_email', 200)->nullable();
            $table->string('manager_name', 150)->nullable();

            // Manager choices
            $table->string('laptop_status', 20)->nullable()
                  ->comment('new | used | none');
            $table->string('internet_level', 20)->nullable()
                  ->comment('business | site | high | vip');
            $table->boolean('needs_extension')->nullable();
            $table->foreignId('floor_id')->nullable()->constrained('network_floors')->nullOnDelete();
            $table->json('selected_group_ids')->nullable()
                  ->comment('Array of IdentityGroup IDs chosen by the manager');
            $table->text('manager_comments')->nullable();

            // Tracking
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_manager_tokens');
    }
};
