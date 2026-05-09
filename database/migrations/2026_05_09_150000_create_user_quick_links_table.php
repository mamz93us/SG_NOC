<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user customizable quick links shown on the welcome screen. Each user
 * pins their own shortcuts independently of any other user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_quick_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('url', 500);
            $table->string('icon', 50)->default('bi-link-45deg');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quick_links');
    }
};
