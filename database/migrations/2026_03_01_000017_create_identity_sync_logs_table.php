<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['users', 'licenses', 'groups', 'full'])->default('full')->index();
            $table->enum('status', ['started', 'completed', 'failed'])->default('started')->index();
            $table->unsignedInteger('users_synced')->default(0);
            $table->unsignedInteger('licenses_synced')->default(0);
            $table->unsignedInteger('groups_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_sync_logs');
    }
};
