<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('form_templates')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('label', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedSmallInteger('uses_limit')->nullable(); // null = unlimited
            $table->unsignedSmallInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_tokens');
    }
};
