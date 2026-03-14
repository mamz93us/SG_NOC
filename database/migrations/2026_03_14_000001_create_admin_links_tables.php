<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_link_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('admin_links', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained('admin_link_categories')->cascadeOnDelete();
            $table->string('url');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('admin_links')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('clicked_at');
        });

        Schema::create('user_favorite_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('admin_links')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorite_links');
        Schema::dropIfExists('admin_link_clicks');
        Schema::dropIfExists('admin_links');
        Schema::dropIfExists('admin_link_categories');
    }
};
